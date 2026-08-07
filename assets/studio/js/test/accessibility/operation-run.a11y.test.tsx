import { screen } from '@testing-library/react'
import { afterEach, describe, it, vi } from 'vitest'
import { OperationRunPanel } from '../../src/modules/asset-pilot/components/operations/operation-run-panel'
import { RecentOperationRuns } from '../../src/modules/asset-pilot/components/operations/recent-operation-runs'
import { assetPilotApi } from '../../src/modules/asset-pilot/services/api'
import type { OperationRun } from '../../src/modules/asset-pilot/types'
import { expectNoAccessibilityViolations } from '../accessibility'
import { renderWithI18n } from '../render'

vi.mock('../../src/modules/asset-pilot/hooks/use-permissions', () => ({
  usePermissions: () => ({ view: true, operate: true, admin: true }),
}))

describe('operation run accessibility', () => {
  afterEach(() => vi.restoreAllMocks())

  it('has no detectable violations for recent run history', async () => {
    vi.spyOn(assetPilotApi, 'getOperationRuns').mockResolvedValue({
      limit: 20,
      items: [{
        id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
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
        createdAt: '2026-07-15 10:00:00',
        startedAt: '2026-07-15 10:00:01',
        updatedAt: '2026-07-15 10:00:02',
        completedAt: null,
      }],
    })

    const { container } = renderWithI18n(<RecentOperationRuns />)
    await screen.findByText('organize')

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations for completed progress', async () => {
    const run: OperationRun = {
      id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      kind: 'organize',
      status: 'completed',
      totalCount: 1,
      processedCount: 1,
      succeededCount: 1,
      skippedCount: 0,
      blockedCount: 0,
      failedCount: 0,
      attempt: 1,
      retryOf: null,
      request: {},
      error: null,
      createdAt: '2026-07-15 10:00:00',
      startedAt: '2026-07-15 10:00:01',
      updatedAt: '2026-07-15 10:00:02',
      completedAt: '2026-07-15 10:00:02',
      items: [],
    }
    vi.spyOn(assetPilotApi, 'getOperationRun').mockResolvedValue(run)

    const { container } = renderWithI18n(<OperationRunPanel runId={run.id} />)
    await screen.findByText('Completed')

    await expectNoAccessibilityViolations(container)
  })
})
