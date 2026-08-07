import { afterEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithI18n } from '../../../../../test/render'
import { assetPilotApi } from '../../services/api'
import type { OperationRunSummary } from '../../types'
import { RecentOperationRuns } from './recent-operation-runs'

vi.mock('./operation-run-panel', () => ({
  OperationRunPanel: ({ runId, onRunIdChange }: { runId: string; onRunIdChange: (id: string) => void }) => (
    <div>
      <span data-testid="selected-run">{runId}</span>
      <button onClick={() => onRunIdChange(retryRunId)}>Simulate retry</button>
    </div>
  ),
}))

const retryRunId = 'fedcba9876543210fedcba9876543210'
const run: OperationRunSummary = {
  id: '0123456789abcdef0123456789abcdef',
  kind: 'organize',
  status: 'running',
  totalCount: 4,
  processedCount: 2,
  succeededCount: 2,
  skippedCount: 0,
  blockedCount: 0,
  failedCount: 0,
  attempt: 1,
  retryOf: null,
  request: {},
  error: null,
  createdAt: '2026-07-15T08:00:00+00:00',
  startedAt: '2026-07-15T08:00:01+00:00',
  updatedAt: '2026-07-15T08:00:02+00:00',
  completedAt: null,
}

describe('RecentOperationRuns', () => {
  afterEach(() => vi.restoreAllMocks())

  it('shows actor-scoped run summaries and opens the selected run', async () => {
    vi.spyOn(assetPilotApi, 'getOperationRuns').mockResolvedValue({ items: [run], limit: 20 })
    const user = userEvent.setup()

    renderWithI18n(<RecentOperationRuns />)

    expect(await screen.findByText('organize')).toBeInTheDocument()
    expect(screen.getByText('Running')).toBeInTheDocument()
    expect(screen.getByText('2/4')).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: `Run ${run.id} progress: 2 of 4 targets processed` })).toHaveAttribute('value', '2')

    const open = screen.getByRole('button', { name: `View details for run ${run.id}` })
    expect(open).toHaveAttribute('aria-pressed', 'false')
    await user.click(open)

    expect(screen.getByTestId('selected-run')).toHaveTextContent(run.id)
    expect(open).toHaveAttribute('aria-pressed', 'true')
  })

  it('recovers from an initial load failure with manual refresh', async () => {
    vi.spyOn(assetPilotApi, 'getOperationRuns')
      .mockRejectedValueOnce(new Error('Network unavailable'))
      .mockResolvedValueOnce({ items: [], limit: 20 })
    const user = userEvent.setup()

    renderWithI18n(<RecentOperationRuns />)

    expect(await screen.findByRole('alert')).toHaveTextContent('Network unavailable')
    await user.click(screen.getByRole('button', { name: 'Refresh runs' }))

    await waitFor(() => expect(screen.getByText('No operation runs found for your user account.')).toBeInTheDocument())
  })
  it('refreshes history after a retry creates a new run', async () => {
    const retried: OperationRunSummary = { ...run, id: retryRunId, kind: 'reorganize', retryOf: run.id }
    const getRuns = vi.spyOn(assetPilotApi, 'getOperationRuns')
      .mockResolvedValueOnce({ items: [run], limit: 20 })
      .mockResolvedValueOnce({ items: [retried], limit: 20 })
    const user = userEvent.setup()

    renderWithI18n(<RecentOperationRuns />)

    await screen.findByText('organize')
    await user.click(screen.getByRole('button', { name: `View details for run ${run.id}` }))
    await user.click(screen.getByRole('button', { name: 'Simulate retry' }))

    await waitFor(() => expect(getRuns).toHaveBeenCalledTimes(2))
    expect(await screen.findByText('reorganize')).toBeInTheDocument()
    expect(screen.getByTestId('selected-run')).toHaveTextContent(retryRunId)
  })

})
