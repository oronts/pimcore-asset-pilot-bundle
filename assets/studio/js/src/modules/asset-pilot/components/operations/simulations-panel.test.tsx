import { afterEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithI18n } from '../../../../../test/render'
import { assetPilotApi } from '../../services/api'
import type { OperationRun, OperationRunSummary } from '../../types'
import { SimulationsPanel } from './simulations-panel'

const toast = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('../../hooks/use-toast', () => ({ useToast: () => toast }))

const simulation: OperationRunSummary = {
  id: '0123456789abcdef0123456789abcdef',
  kind: 'simulation',
  status: 'completed',
  totalCount: 2,
  processedCount: 0,
  succeededCount: 0,
  skippedCount: 0,
  blockedCount: 0,
  failedCount: 0,
  attempt: 1,
  retryOf: null,
  request: { objectId: 42 },
  error: null,
  createdAt: '2026-08-05T08:00:00+00:00',
  startedAt: null,
  updatedAt: '2026-08-05T08:00:00+00:00',
  completedAt: '2026-08-05T08:00:00+00:00',
}

const detail: OperationRun = {
  ...simulation,
  items: [
    {
      key: 'asset:12',
      targetType: 'asset',
      targetId: 12,
      fingerprint: null,
      status: 'queued',
      attempts: 0,
      state: { from: '/Uploads/a.jpg', to: '/Photos/a.jpg', ruleName: 'images' },
      result: null,
      error: null,
      createdAt: '2026-08-05T08:00:00+00:00',
      updatedAt: '2026-08-05T08:00:00+00:00',
      completedAt: null,
    },
  ],
}

describe('SimulationsPanel', () => {
  afterEach(() => vi.restoreAllMocks())

  it('lists recorded simulations and shows their move diff on demand', async () => {
    vi.spyOn(assetPilotApi, 'getSimulations').mockResolvedValue({ items: [simulation], limit: 20 })
    vi.spyOn(assetPilotApi, 'getOperationRun').mockResolvedValue(detail)
    const user = userEvent.setup()

    renderWithI18n(<SimulationsPanel />)

    const viewButton = await screen.findByRole('button', { name: /view the diff/i })
    await user.click(viewButton)

    await waitFor(() => expect(screen.getByText('/Photos/a.jpg')).toBeInTheDocument())
    expect(screen.getByText('/Uploads/a.jpg')).toBeInTheDocument()
  })

  it('records a simulation for the entered object id', async () => {
    vi.spyOn(assetPilotApi, 'getSimulations').mockResolvedValue({ items: [], limit: 20 })
    const simulate = vi.spyOn(assetPilotApi, 'simulate').mockResolvedValue({
      runId: 'r1',
      operations: [{ assetId: 12, sourcePath: '/a', targetPath: '/b', ruleName: 'images' }],
    })
    const user = userEvent.setup()

    renderWithI18n(<SimulationsPanel />)

    await user.type(screen.getByPlaceholderText('Object ID'), '42')
    await user.click(screen.getByRole('button', { name: 'Simulate & save' }))

    await waitFor(() => expect(simulate).toHaveBeenCalledWith(42))
    expect(toast.success).toHaveBeenCalled()
  })
})
