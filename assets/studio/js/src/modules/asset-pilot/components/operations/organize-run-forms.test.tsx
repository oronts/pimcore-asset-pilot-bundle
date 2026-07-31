import { describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import { BulkOrganizeForm } from './bulk-organize-form'
import { OrganizeForm } from './organize-form'

const toast = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))

vi.mock('../../hooks/use-toast', () => ({ useToast: () => toast }))
vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => ({ operate: true }) }))
vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useRules: () => ({
    data: [{ name: 'product-assets', class: 'Product' }],
    loading: false,
    error: null,
    refetch: vi.fn(),
  }),
}))
vi.mock('../shared/open-button', () => ({ OpenButton: ({ id }: { id: number }) => <span>{id}</span> }))
vi.mock('./operation-run-panel', () => ({
  OperationRunPanel: ({ runId }: { runId: string }) => <output aria-label="Tracked operation run">{runId}</output>,
}))

const runId = '0123456789abcdef0123456789abcdef'

describe('organize run integration', () => {
  it('tracks the run returned by single-object organization', async () => {
    const organize = vi.spyOn(assetPilotApi, 'organize')
      .mockResolvedValueOnce({ dryRun: true, planToken: 'single-plan', operations: [] })
      .mockResolvedValueOnce({ runId, results: [] })
    const user = userEvent.setup()

    renderWithI18n(<OrganizeForm />)
    await user.type(screen.getByRole('spinbutton', { name: 'Object ID' }), '42')
    await user.click(screen.getByRole('button', { name: 'Preview' }))
    await user.click(await screen.findByRole('button', { name: 'Apply Reviewed Plan' }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Organize' }))

    expect(await screen.findByRole('status', { name: 'Tracked operation run' })).toHaveTextContent(runId)
    expect(organize).toHaveBeenNthCalledWith(1, { objectId: 42, dryRun: true, async: false }, expect.any(AbortSignal))
    expect(organize).toHaveBeenNthCalledWith(2, { objectId: 42, dryRun: false, async: false, planToken: 'single-plan' }, expect.any(AbortSignal))
  })

  it('tracks the run returned by queued bulk organization', async () => {
    vi.spyOn(assetPilotApi, 'organizeBulkPreview').mockResolvedValue({
      objects: [{ id: 42, key: 'product-42', className: 'Product' }],
      total: null,
      page: 1,
      pages: null,
      hasMore: false,
      truncated: false,
    })
    const organize = vi.spyOn(assetPilotApi, 'organizeBulk')
      .mockResolvedValueOnce({ dryRun: true, planToken: 'bulk-plan', objectCount: 1, operations: [] })
      .mockResolvedValueOnce({ runId, message: 'Queued', objectCount: 1, batchCount: 1 })
    const user = userEvent.setup()

    renderWithI18n(<BulkOrganizeForm />)
    await user.selectOptions(screen.getByRole('combobox', { name: 'Select Class...' }), 'Product')
    await user.click(screen.getByRole('button', { name: 'Browse Candidates' }))
    await user.click(await screen.findByRole('button', { name: 'Review Organization' }))
    await user.click(await screen.findByRole('button', { name: 'Organize Reviewed Objects' }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Confirm' }))

    expect(await screen.findByRole('status', { name: 'Tracked operation run' })).toHaveTextContent(runId)
    expect(organize).toHaveBeenNthCalledWith(1, { className: 'Product', dryRun: true, async: true, batchSize: 50 }, expect.any(AbortSignal))
    expect(organize).toHaveBeenNthCalledWith(2, { className: 'Product', dryRun: false, async: true, batchSize: 50, planToken: 'bulk-plan' }, expect.any(AbortSignal))
  })

  it('paginates the bulk preview by cursor and warns when the scan is truncated', async () => {
    vi.spyOn(assetPilotApi, 'organizeBulkPreview').mockResolvedValue({
      objects: [{ id: 1, key: 'product-1', className: 'Product' }, { id: 2, key: 'product-2', className: 'Product' }],
      total: null,
      page: 1,
      pages: null,
      hasMore: true,
      truncated: true,
    })
    const user = userEvent.setup()

    renderWithI18n(<BulkOrganizeForm />)
    await user.selectOptions(screen.getByRole('combobox', { name: 'Select Class...' }), 'Product')
    await user.click(screen.getByRole('button', { name: 'Browse Candidates' }))

    expect(await screen.findByText('Showing 2 on this page')).toBeInTheDocument()
    expect(screen.getByText(/scan ceiling/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled()
    expect(await screen.findByRole('button', { name: 'Review Organization' })).toBeEnabled()
  })

  it('invalidates a single-object plan when async mode changes', async () => {
    vi.spyOn(assetPilotApi, 'organize').mockResolvedValue({ dryRun: true, planToken: 'single-plan', operations: [] })
    const user = userEvent.setup()

    renderWithI18n(<OrganizeForm />)
    await user.type(screen.getByRole('spinbutton', { name: 'Object ID' }), '42')
    await user.click(screen.getByRole('button', { name: 'Preview' }))
    expect(await screen.findByRole('button', { name: 'Apply Reviewed Plan' })).toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: 'Async' }))

    expect(screen.queryByRole('button', { name: 'Apply Reviewed Plan' })).not.toBeInTheDocument()
  })

  it('clears the preview after a stale-plan conflict', async () => {
    vi.spyOn(assetPilotApi, 'organize')
      .mockResolvedValueOnce({ dryRun: true, planToken: 'single-plan', operations: [] })
      .mockRejectedValueOnce(new ApiError('stale', 409))
    const user = userEvent.setup()

    renderWithI18n(<OrganizeForm />)
    await user.type(screen.getByRole('spinbutton', { name: 'Object ID' }), '42')
    await user.click(screen.getByRole('button', { name: 'Preview' }))
    await user.click(await screen.findByRole('button', { name: 'Apply Reviewed Plan' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Organize' }))

    expect(toast.warning).toHaveBeenCalledWith('The reviewed plan is no longer current. Preview again before applying.')
    expect(screen.queryByRole('button', { name: 'Apply Reviewed Plan' })).not.toBeInTheDocument()
  })
})
