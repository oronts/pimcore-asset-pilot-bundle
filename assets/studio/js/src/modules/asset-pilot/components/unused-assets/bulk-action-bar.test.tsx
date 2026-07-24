import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import type { PlannedBulkActionResult } from '../../types'
import { BulkActionBar } from './bulk-action-bar'

const toast = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))

vi.mock('../../hooks/use-toast', () => ({ useToast: () => toast }))
vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => ({ operate: true }) }))

const preview = (overrides: Partial<PlannedBulkActionResult> = {}): PlannedBulkActionResult => ({
  failed: 1,
  errors: { 8: 'Asset is locked' },
  observerWarnings: [],
  dryRun: true,
  planToken: 'reviewed-plan',
  eligible: 1,
  ...overrides,
})

describe('BulkActionBar immutable plans', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    toast.success.mockReset()
    toast.warning.mockReset()
    toast.error.mockReset()
  })

  it('rejects a response that is not a signed dry-run plan', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkDeleteAssets').mockResolvedValue(preview({ dryRun: false }))
    const user = userEvent.setup()

    renderWithI18n(
      <BulkActionBar count={2} assetIds={[7, 8]} onActionComplete={vi.fn()} onDeselect={vi.fn()} />,
    )
    await user.click(screen.getByRole('button', { name: 'Review Deletion' }))

    expect(toast.error).toHaveBeenCalledWith('The server did not return a usable signed action preview.')
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('shows eligibility errors before applying the exact reviewed deletion', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkDeleteAssets').mockResolvedValue(preview())
    vi.spyOn(assetPilotApi, 'applyBulkDeleteAssets').mockResolvedValue(preview({
      deleted: 1,
      dryRun: false,
      planToken: null,
    }))
    const completed = vi.fn()
    const user = userEvent.setup()

    renderWithI18n(
      <BulkActionBar count={2} assetIds={[7, 8]} onActionComplete={completed} onDeselect={vi.fn()} />,
    )
    await user.click(screen.getByRole('button', { name: 'Review Deletion' }))

    const dialog = await screen.findByRole('alertdialog', { name: 'Confirm Deletion' })
    expect(within(dialog).getByText('Eligible: 1, blocked: 1')).toBeInTheDocument()
    expect(within(dialog).getByText('Asset #8: Asset is locked')).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Confirm Delete' }))

    expect(assetPilotApi.previewBulkDeleteAssets).toHaveBeenCalledWith([7, 8], expect.any(AbortSignal))
    expect(assetPilotApi.applyBulkDeleteAssets).toHaveBeenCalledWith([7, 8], 'reviewed-plan', expect.any(AbortSignal))
    expect(completed).toHaveBeenCalledWith('delete', expect.objectContaining({ deleted: 1 }))
  })

  it('invalidates a reviewed move whenever the target changes', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkMoveAssets')
      .mockResolvedValueOnce(preview({ failed: 0, errors: {}, eligible: 2, planToken: 'old-target-plan' }))
      .mockResolvedValueOnce(preview({ failed: 0, errors: {}, eligible: 2, planToken: 'new-target-plan' }))
    const user = userEvent.setup()

    renderWithI18n(
      <BulkActionBar count={2} assetIds={[7, 8]} onActionComplete={vi.fn()} onDeselect={vi.fn()} />,
    )
    await user.click(screen.getByRole('button', { name: 'Move Selected' }))
    await user.type(screen.getByPlaceholderText('/target/folder'), '/archive/original')
    await user.click(screen.getByRole('button', { name: 'Review Move' }))
    expect(await screen.findByRole('alertdialog')).toBeInTheDocument()

    const target = screen.getByPlaceholderText('/target/folder')
    await user.clear(target)
    await user.type(target, '/archive/reviewed')

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Review Move' }))
    expect(await screen.findByRole('alertdialog')).toHaveAccessibleDescription(expect.stringContaining('/archive/reviewed'))
    expect(assetPilotApi.previewBulkMoveAssets).toHaveBeenLastCalledWith([7, 8], '/archive/reviewed', expect.any(AbortSignal))
  })

  it('clears the destructive preview after an apply conflict', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkQuarantineAssets').mockResolvedValue(preview({ failed: 0, errors: {}, eligible: 2 }))
    vi.spyOn(assetPilotApi, 'applyBulkQuarantineAssets').mockRejectedValue(new ApiError('stale', 409))
    const user = userEvent.setup()

    renderWithI18n(
      <BulkActionBar count={2} assetIds={[7, 8]} onActionComplete={vi.fn()} onDeselect={vi.fn()} />,
    )
    await user.click(screen.getByRole('button', { name: 'Review Quarantine' }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Confirm Quarantine' }))

    expect(toast.warning).toHaveBeenCalledWith('The selected assets changed after review. Preview the action again.')
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('invalidates a reviewed action when the selection changes', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkDeleteAssets').mockResolvedValue(preview())
    const props = { count: 2, onActionComplete: vi.fn(), onDeselect: vi.fn() }
    const user = userEvent.setup()
    const view = renderWithI18n(<BulkActionBar {...props} assetIds={[7, 8]} />)

    await user.click(screen.getByRole('button', { name: 'Review Deletion' }))
    expect(await screen.findByRole('alertdialog')).toBeInTheDocument()

    view.rerender(<BulkActionBar {...props} count={1} assetIds={[7]} />)

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('keeps unlock reachable for a selection that includes locked assets', async () => {
    const unlock = vi.spyOn(assetPilotApi, 'unlockAsset').mockResolvedValue({ message: 'ok', assetId: 8 })
    const user = userEvent.setup()

    renderWithI18n(
      <BulkActionBar count={2} assetIds={[7, 8]} lockedIds={[8]} onActionComplete={vi.fn()} onDeselect={vi.fn()} onLockDone={vi.fn()} />,
    )
    await user.click(screen.getByRole('button', { name: 'Unlock Selected' }))

    await waitFor(() => expect(unlock).toHaveBeenCalledWith(8))
  })

  it('excludes locked assets from a destructive plan and flags them as skipped', async () => {
    const del = vi.spyOn(assetPilotApi, 'previewBulkDeleteAssets').mockResolvedValue(preview({ failed: 0, errors: {}, eligible: 1 }))
    const user = userEvent.setup()

    renderWithI18n(
      <BulkActionBar count={2} assetIds={[7, 8]} lockedIds={[8]} onActionComplete={vi.fn()} onDeselect={vi.fn()} />,
    )
    expect(screen.getByText('1 protected asset(s) will be skipped for edits')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Review Deletion' }))

    await waitFor(() => expect(del).toHaveBeenCalledWith([7], expect.any(AbortSignal)))
  })
})
