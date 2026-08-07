import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import type { OperationRecoveryResponse } from '../../types'
import { OperationRecoveryPanel } from './operation-recovery-panel'

const permissions = vi.hoisted(() => ({ admin: true }))

vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => permissions }))
vi.mock('../shared/open-button', () => ({ OpenButton: ({ id }: { id: number }) => <span>Asset #{id}</span> }))

const preview: OperationRecoveryResponse = {
  applied: false,
  planToken: 'signed-recovery-plan',
  unresolved: 2,
  results: [
    {
      operationId: 91,
      assetId: 7,
      kind: 'move',
      classification: 'completed',
      journalUpdated: false,
      message: 'The move committed.',
    },
    {
      operationId: 92,
      assetId: 8,
      kind: 'revert',
      classification: 'recovery_required',
      journalUpdated: false,
      message: 'The asset is unavailable.',
    },
  ],
}

const applied: OperationRecoveryResponse = {
  applied: true,
  planToken: null,
  unresolved: 1,
  results: [
    { ...preview.results[0], journalUpdated: true },
    { ...preview.results[1], journalUpdated: false },
  ],
}

afterEach(() => {
  vi.restoreAllMocks()
  permissions.admin = true
})

describe('OperationRecoveryPanel', () => {
  it('is not exposed without admin permission', () => {
    permissions.admin = false

    renderWithI18n(<OperationRecoveryPanel />)

    expect(screen.queryByRole('heading', { name: 'Operation recovery' })).not.toBeInTheDocument()
  })

  it('reviews the exact limit, retains the token, confirms apply, and reports unresolved results', async () => {
    const previewRequest = vi.spyOn(assetPilotApi, 'previewOperationRecovery').mockResolvedValue(preview)
    const applyRequest = vi.spyOn(assetPilotApi, 'applyOperationRecovery').mockResolvedValue(applied)
    const user = userEvent.setup()

    renderWithI18n(<OperationRecoveryPanel />)
    const limit = screen.getByRole('spinbutton', { name: 'Recovery review limit' })
    await user.clear(limit)
    await user.type(limit, '25')
    await user.click(screen.getByRole('button', { name: 'Review recoverable operations' }))

    expect(previewRequest).toHaveBeenCalledWith(25, expect.any(AbortSignal))
    const reviewedTable = await screen.findByRole('table', { name: 'Reviewed recovery results' })
    expect(within(reviewedTable).getByText('91')).toBeInTheDocument()
    expect(within(reviewedTable).getByText('Asset #7')).toBeInTheDocument()
    expect(within(reviewedTable).getByText('The move committed.')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Reviewed 2 operation(s). 2 await a journal resolution.')

    await user.click(screen.getByRole('button', { name: 'Apply reviewed recovery' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Apply reviewed operation recovery' })
    expect(dialog).toHaveAccessibleDescription(expect.stringContaining('exactly 2 reviewed operation(s) using review limit 25'))
    await user.click(within(dialog).getByRole('button', { name: 'Apply reviewed recovery' }))

    expect(applyRequest).toHaveBeenCalledWith(25, 'signed-recovery-plan', expect.any(AbortSignal))
    expect(await screen.findByRole('alert')).toHaveTextContent('1 operation(s) still require attention')
    const appliedTable = screen.getByRole('table', { name: 'Applied recovery results' })
    expect(within(appliedTable).getByText('Updated')).toBeInTheDocument()
    expect(within(appliedTable).getByText('Not updated')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Apply reviewed recovery' })).not.toBeInTheDocument()
  })

  it('aborts an in-flight apply request when the panel unmounts', async () => {
    vi.spyOn(assetPilotApi, 'previewOperationRecovery').mockResolvedValue(preview)
    const applyRequest = vi.spyOn(assetPilotApi, 'applyOperationRecovery')
      .mockImplementation(() => new Promise<OperationRecoveryResponse>(() => {}))
    const user = userEvent.setup()
    const view = renderWithI18n(<OperationRecoveryPanel />)

    await user.click(screen.getByRole('button', { name: 'Review recoverable operations' }))
    await user.click(await screen.findByRole('button', { name: 'Apply reviewed recovery' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Apply reviewed recovery' }))

    await waitFor(() => expect(applyRequest).toHaveBeenCalled())
    const applySignal = applyRequest.mock.calls[0]?.[2]
    if (applySignal == null) throw new Error('Expected an apply request signal.')

    expect(applySignal.aborted).toBe(false)
    view.unmount()

    expect(applySignal.aborted).toBe(true)
  })

  it('invalidates a stale signed review and requires a fresh preview', async () => {
    vi.spyOn(assetPilotApi, 'previewOperationRecovery').mockResolvedValue(preview)
    vi.spyOn(assetPilotApi, 'applyOperationRecovery').mockRejectedValue(new ApiError('stale', 409))
    const user = userEvent.setup()

    renderWithI18n(<OperationRecoveryPanel />)
    await user.click(screen.getByRole('button', { name: 'Review recoverable operations' }))
    await user.click(await screen.findByRole('button', { name: 'Apply reviewed recovery' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Apply reviewed recovery' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('changed, expired, or was already applied')
    expect(screen.queryByRole('table', { name: 'Reviewed recovery results' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Apply reviewed recovery' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Review recoverable operations' })).toBeEnabled()
  })

  it('rejects invalid limits and unsigned non-empty previews', async () => {
    vi.spyOn(assetPilotApi, 'previewOperationRecovery').mockResolvedValue({ ...preview, planToken: null })
    const user = userEvent.setup()

    renderWithI18n(<OperationRecoveryPanel />)
    const limit = screen.getByRole('spinbutton', { name: 'Recovery review limit' })
    await user.clear(limit)
    await user.type(limit, '1001')

    expect(screen.getByText('The review limit must be an integer from 1 to 1000.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Review recoverable operations' })).toBeDisabled()

    await user.clear(limit)
    await user.type(limit, '100')
    await user.click(screen.getByRole('button', { name: 'Review recoverable operations' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('usable signed recovery review')
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
