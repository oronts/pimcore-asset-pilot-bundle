import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import { ReorganizeForm } from './reorganize-form'
import { ReplayFailuresForm } from './replay-failures-form'

const toast = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))

vi.mock('../../hooks/use-toast', () => ({ useToast: () => toast }))
vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => ({ operate: true }) }))
vi.mock('./operation-run-panel', () => ({
  OperationRunPanel: ({ runId }: { runId: string }) => <output aria-label="Tracked operation run">{runId}: queued</output>,
}))

const operation = {
  assetId: 7,
  sourcePath: '/Staging/source.png',
  targetPath: '/Organized/source.png',
  ruleName: 'product-assets',
  objectId: 42,
}

const reorganizePreview = {
  assetsScanned: 3,
  ownerObjects: 2,
  truncated: false,
  dryRun: true,
  planToken: 'reorganize-plan',
  runId: null,
  statusUrl: null,
  objectCount: 2,
  operations: [operation],
  organized: 0,
  dispatched: 0,
  skipped: 0,
  failed: 0,
}

const replayPreview = {
  candidates: 2,
  dryRun: true,
  planToken: 'replay-plan',
  runId: null,
  statusUrl: null,
  objectCount: 2,
  operations: [operation],
  organized: 0,
  dispatched: 0,
  skipped: 0,
  failed: 0,
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.clearAllMocks()
})

describe('ReorganizeForm reviewed execution', () => {
  it('rejects a review response without a signed plan', async () => {
    vi.spyOn(assetPilotApi, 'reorganize').mockResolvedValue({ ...reorganizePreview, planToken: null })
    const user = userEvent.setup()

    renderWithI18n(<ReorganizeForm />)
    await user.type(screen.getByRole('textbox', { name: 'Asset folder' }), '/Staging')
    await user.click(screen.getByRole('button', { name: 'Review reorganization' }))

    expect(toast.error).toHaveBeenCalledWith('The server did not return a usable signed preview.')
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('previews counts and cancel does not mutate', async () => {
    const reorganize = vi.spyOn(assetPilotApi, 'reorganize').mockResolvedValue(reorganizePreview)
    const user = userEvent.setup()

    renderWithI18n(<ReorganizeForm />)
    await user.type(screen.getByRole('textbox', { name: 'Asset folder' }), ' /Staging ')
    const limit = screen.getByRole('spinbutton', { name: 'Limit' })
    await user.clear(limit)
    await user.type(limit, '25')
    await user.click(screen.getByRole('button', { name: 'Review reorganization' }))

    const dialog = await screen.findByRole('alertdialog', { name: 'Reorganize existing assets' })
    expect(dialog).toHaveAccessibleDescription(
      'Apply the reviewed plan for 3 asset(s), 2 referring object(s), and 1 planned operation(s) below /Staging.',
    )
    expect(reorganize).toHaveBeenCalledOnce()
    expect(reorganize).toHaveBeenCalledWith({
      folder: '/Staging',
      limit: 25,
      async: true,
      dryRun: true,
    }, expect.any(AbortSignal))

    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(reorganize).toHaveBeenCalledOnce()
  })

  it('applies the exact reviewed token and surfaces the run', async () => {
    const runId = 'reorganize-run'
    const reorganize = vi.spyOn(assetPilotApi, 'reorganize')
      .mockResolvedValueOnce(reorganizePreview)
      .mockResolvedValueOnce({
        ...reorganizePreview,
        dryRun: false,
        planToken: null,
        runId,
        statusUrl: '/operations/runs/reorganize-run',
        operations: undefined,
        dispatched: 2,
      })
    const user = userEvent.setup()

    renderWithI18n(<ReorganizeForm />)
    await user.type(screen.getByRole('textbox', { name: 'Asset folder' }), '/Staging')
    await user.click(screen.getByRole('button', { name: 'Review reorganization' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Apply reviewed reorganization' }))

    expect(reorganize).toHaveBeenNthCalledWith(2, {
      folder: '/Staging',
      limit: 100,
      async: true,
      dryRun: false,
      planToken: 'reorganize-plan',
    }, expect.any(AbortSignal))
    expect(await screen.findByRole('status', { name: 'Tracked operation run' })).toHaveTextContent(runId + ': queued')
  })

  it('discards a stale reviewed plan', async () => {
    vi.spyOn(assetPilotApi, 'reorganize')
      .mockResolvedValueOnce(reorganizePreview)
      .mockRejectedValueOnce(new ApiError('stale', 409))
    const user = userEvent.setup()

    renderWithI18n(<ReorganizeForm />)
    await user.type(screen.getByRole('textbox', { name: 'Asset folder' }), '/Staging')
    await user.click(screen.getByRole('button', { name: 'Review reorganization' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Apply reviewed reorganization' }))

    expect(toast.warning).toHaveBeenCalledWith('The reviewed plan is no longer current. Preview again before applying.')
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })
})

describe('ReplayFailuresForm reviewed execution', () => {
  it('rejects a review response without a signed plan', async () => {
    vi.spyOn(assetPilotApi, 'replayFailures').mockResolvedValue({ ...replayPreview, planToken: '' })
    const user = userEvent.setup()

    renderWithI18n(<ReplayFailuresForm />)
    await user.click(screen.getByRole('button', { name: 'Review failure replay' }))

    expect(toast.error).toHaveBeenCalledWith('The server did not return a usable signed preview.')
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('previews counts and cancel does not mutate', async () => {
    const replay = vi.spyOn(assetPilotApi, 'replayFailures').mockResolvedValue(replayPreview)
    const user = userEvent.setup()

    renderWithI18n(<ReplayFailuresForm />)
    await user.type(screen.getByRole('textbox', { name: 'Rule' }), 'failed-rule')
    await user.click(screen.getByRole('button', { name: 'Review failure replay' }))

    const dialog = await screen.findByRole('alertdialog', { name: 'Replay reviewed failures' })
    expect(dialog).toHaveAccessibleDescription(
      'Apply the reviewed plan for 2 object(s) and 1 planned operation(s).',
    )
    expect(replay).toHaveBeenCalledOnce()
    expect(replay).toHaveBeenCalledWith({
      async: true,
      since: undefined,
      rule: 'failed-rule',
      class: undefined,
      limit: 100,
      dryRun: true,
    }, expect.any(AbortSignal))

    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(replay).toHaveBeenCalledOnce()
  })

  it('converts the browser-local since into an explicit UTC instant for preview and apply', async () => {
    const replay = vi.spyOn(assetPilotApi, 'replayFailures')
      .mockResolvedValueOnce(replayPreview)
      .mockResolvedValueOnce({ ...replayPreview, dryRun: false, runId: 'r', runStatus: 'queued', dispatched: 1, organized: 0, failed: 0, skipped: 0, statusUrl: '/x' })
    const user = userEvent.setup()

    renderWithI18n(<ReplayFailuresForm />)
    fireEvent.change(screen.getByLabelText('Failed since'), { target: { value: '2026-07-18T12:00' } })
    await user.click(screen.getByRole('button', { name: 'Review failure replay' }))

    const dialog = await screen.findByRole('alertdialog', { name: 'Replay reviewed failures' })
    const expectedInstant = new Date('2026-07-18T12:00').toISOString()
    expect(replay).toHaveBeenLastCalledWith(expect.objectContaining({ since: expectedInstant, dryRun: true }), expect.any(AbortSignal))

    await user.click(within(dialog).getByRole('button', { name: 'Replay reviewed failures' }))
    expect(replay).toHaveBeenLastCalledWith(expect.objectContaining({ since: expectedInstant, dryRun: false }), expect.any(AbortSignal))
  })

  it('applies the exact reviewed token and surfaces the run', async () => {
    const runId = 'replay-run'
    const replay = vi.spyOn(assetPilotApi, 'replayFailures')
      .mockResolvedValueOnce(replayPreview)
      .mockResolvedValueOnce({
        ...replayPreview,
        dryRun: false,
        planToken: null,
        runId,
        statusUrl: '/operations/runs/replay-run',
        operations: undefined,
        dispatched: 2,
      })
    const user = userEvent.setup()

    renderWithI18n(<ReplayFailuresForm />)
    await user.type(screen.getByRole('textbox', { name: 'Rule' }), 'failed-rule')
    await user.click(screen.getByRole('button', { name: 'Review failure replay' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Replay reviewed failures' }))

    expect(replay).toHaveBeenNthCalledWith(2, {
      async: true,
      since: undefined,
      rule: 'failed-rule',
      class: undefined,
      limit: 100,
      dryRun: false,
      planToken: 'replay-plan',
    }, expect.any(AbortSignal))
    expect(await screen.findByRole('status', { name: 'Tracked operation run' })).toHaveTextContent(runId + ': queued')
  })

  it('reports an apply error and does not surface a run', async () => {
    vi.spyOn(assetPilotApi, 'replayFailures')
      .mockResolvedValueOnce(replayPreview)
      .mockRejectedValueOnce(new Error('Replay apply failed'))
    const user = userEvent.setup()

    renderWithI18n(<ReplayFailuresForm />)
    await user.click(screen.getByRole('button', { name: 'Review failure replay' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Replay reviewed failures' }))

    expect(toast.error).toHaveBeenCalledWith('Replay apply failed')
    expect(screen.queryByRole('status', { name: 'Tracked operation run' })).not.toBeInTheDocument()
  })
})
