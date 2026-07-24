import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithI18n } from '../../../../../test/render'
import { assetPilotApi } from '../../services/api'
import type { OperationRun } from '../../types'
import { OperationRunPanel } from './operation-run-panel'

const permissions = vi.hoisted(() => ({ operate: true }))

vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => permissions }))

const runId = '0123456789abcdef0123456789abcdef'
const retryRunId = 'fedcba9876543210fedcba9876543210'

function createRun(overrides: Partial<OperationRun> = {}): OperationRun {
  return {
    id: runId,
    kind: 'organize',
    status: 'running',
    totalCount: 2,
    processedCount: 1,
    succeededCount: 1,
    skippedCount: 0,
    blockedCount: 0,
    failedCount: 0,
    attempt: 1,
    retryOf: null,
    request: { objectId: 42 },
    error: null,
    createdAt: '2026-07-15T08:00:00+00:00',
    startedAt: '2026-07-15T08:00:01+00:00',
    updatedAt: '2026-07-15T08:00:02+00:00',
    completedAt: null,
    items: [
      {
        key: 'object:42',
        targetType: 'data_object',
        targetId: 42,
        fingerprint: 'fingerprint',
        status: 'running',
        attempts: 1,
        state: {},
        result: null,
        error: null,
        createdAt: '2026-07-15T08:00:00+00:00',
        updatedAt: '2026-07-15T08:00:02+00:00',
        completedAt: null,
      },
    ],
    ...overrides,
  }
}

describe('OperationRunPanel', () => {
  afterEach(() => {
    vi.useRealTimers()
    permissions.operate = true
  })

  it('shows live progress and requests cancellation', async () => {
    const run = createRun()
    vi.spyOn(assetPilotApi, 'getOperationRun').mockResolvedValue(run)
    const cancel = vi.spyOn(assetPilotApi, 'cancelOperationRun').mockResolvedValue({
      runId,
      status: 'cancel_requested',
    })
    const user = userEvent.setup()

    renderWithI18n(<OperationRunPanel runId={runId} />)

    expect(await screen.findByText('Running', { selector: 'span' })).toBeInTheDocument()
    expect(screen.getByText(runId)).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: 'Operation progress' })).toHaveAttribute('value', '1')
    expect(screen.getByText('1 of 2 targets processed')).toBeInTheDocument()
    expect(screen.getByText('data_object #42')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Cancel run' }))

    await waitFor(() => expect(cancel).toHaveBeenCalledWith(runId))
    expect(await screen.findByText('Cancellation requested')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel run' })).not.toBeInTheDocument()
  })

  it('switches to the new run returned by retry', async () => {
    const failedRun = createRun({
      status: 'partial',
      processedCount: 2,
      failedCount: 1,
      completedAt: '2026-07-15T08:00:03+00:00',
      items: [{ ...createRun().items[0], status: 'failed', error: 'Temporary failure', completedAt: '2026-07-15T08:00:03+00:00' }],
    })
    const queuedRetry = createRun({
      id: retryRunId,
      status: 'queued',
      processedCount: 0,
      succeededCount: 0,
      failedCount: 0,
      attempt: 2,
      retryOf: runId,
      startedAt: null,
      completedAt: null,
      items: [{ ...createRun().items[0], status: 'queued', attempts: 0 }],
    })
    vi.spyOn(assetPilotApi, 'getOperationRun').mockImplementation(async id => id === retryRunId ? queuedRetry : failedRun)
    vi.spyOn(assetPilotApi, 'retryOperationRun').mockResolvedValue({
      runId: retryRunId,
      retryOf: runId,
      status: 'queued',
      statusUrl: `operations/runs/${retryRunId}`,
    })
    const onRunIdChange = vi.fn()
    const user = userEvent.setup()

    renderWithI18n(<OperationRunPanel runId={runId} onRunIdChange={onRunIdChange} />)

    await user.click(await screen.findByRole('button', { name: 'Retry incomplete targets' }))

    await waitFor(() => expect(onRunIdChange).toHaveBeenCalledWith(retryRunId))
    expect(await screen.findByText(retryRunId)).toBeInTheDocument()
    expect(await screen.findByText('Queued', { selector: 'span' })).toBeInTheDocument()
  })

  it('recovers from a failed load with manual refresh', async () => {
    vi.spyOn(assetPilotApi, 'getOperationRun')
      .mockRejectedValueOnce(new Error('Network unavailable'))
      .mockResolvedValue(createRun({ status: 'completed', processedCount: 2, succeededCount: 2, completedAt: '2026-07-15T08:00:03+00:00' }))
    const user = userEvent.setup()

    renderWithI18n(<OperationRunPanel runId={runId} />)

    expect(await screen.findByRole('alert')).toHaveTextContent('Network unavailable')
    await user.click(screen.getByRole('button', { name: 'Refresh' }))

    expect(await screen.findByText('Completed')).toBeInTheDocument()
  })

  it('does not expose run mutations without operate permission', async () => {
    permissions.operate = false
    vi.spyOn(assetPilotApi, 'getOperationRun').mockResolvedValue(createRun())

    renderWithI18n(<OperationRunPanel runId={runId} />)

    expect(await screen.findByText('Running', { selector: 'span' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel run' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Retry incomplete targets' })).not.toBeInTheDocument()
  })

  it('treats blocked runs as terminal and retries blocked targets', async () => {
    vi.useFakeTimers()
    const blockedRun = createRun({
      status: 'blocked',
      processedCount: 2,
      succeededCount: 1,
      blockedCount: 1,
      completedAt: '2026-07-15T08:00:03+00:00',
      items: [{ ...createRun().items[0], status: 'blocked', error: 'Target changed', completedAt: '2026-07-15T08:00:03+00:00' }],
    })
    const getRun = vi.spyOn(assetPilotApi, 'getOperationRun').mockResolvedValue(blockedRun)

    renderWithI18n(<OperationRunPanel runId={runId} />)

    await act(async () => { await Promise.resolve() })
    expect(screen.getByText('Blocked', { selector: 'span' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry incomplete targets' })).toBeInTheDocument()
    expect(screen.getByText('Blocked', { selector: 'dt' }).nextElementSibling).toHaveTextContent('1')

    await act(async () => { await vi.advanceTimersByTimeAsync(2500) })
    expect(getRun).toHaveBeenCalledTimes(1)
  })

  it('does not offer retry when the only incomplete targets were skipped', async () => {
    vi.spyOn(assetPilotApi, 'getOperationRun').mockResolvedValue(createRun({
      status: 'partial',
      processedCount: 2,
      skippedCount: 1,
      completedAt: '2026-07-15T08:00:03+00:00',
      items: [{ ...createRun().items[0], status: 'skipped', completedAt: '2026-07-15T08:00:03+00:00' }],
    }))

    renderWithI18n(<OperationRunPanel runId={runId} />)

    expect(await screen.findByText('Completed with skipped or failed targets')).toBeInTheDocument()
    expect(screen.getByText('Skipped', { selector: 'td' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Retry incomplete targets' })).not.toBeInTheDocument()
  })

  it('continues polling after a transient refresh failure', async () => {
    vi.useFakeTimers()
    const completed = createRun({
      status: 'completed',
      processedCount: 2,
      succeededCount: 2,
      completedAt: '2026-07-15T08:00:03+00:00',
    })
    const getRun = vi.spyOn(assetPilotApi, 'getOperationRun')
      .mockResolvedValueOnce(createRun())
      .mockRejectedValueOnce(new Error('Temporary network failure'))
      .mockResolvedValueOnce(completed)

    renderWithI18n(<OperationRunPanel runId={runId} />)
    await act(async () => { await Promise.resolve() })
    expect(getRun).toHaveBeenCalledTimes(1)

    await act(async () => { await vi.advanceTimersByTimeAsync(2000) })
    expect(getRun).toHaveBeenCalledTimes(2)
    expect(screen.getByRole('alert')).toHaveTextContent('Temporary network failure')

    await act(async () => { await vi.advanceTimersByTimeAsync(2000) })
    expect(getRun).toHaveBeenCalledTimes(3)
    expect(screen.getByText('Completed', { selector: 'span' })).toBeInTheDocument()
  })
})
