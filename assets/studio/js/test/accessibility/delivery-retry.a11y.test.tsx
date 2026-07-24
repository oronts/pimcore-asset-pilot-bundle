import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { DeliveryRetryPanel } from '../../src/modules/asset-pilot/components/operations/delivery-retry-panel'
import { assetPilotApi } from '../../src/modules/asset-pilot/services/api'
import { expectNoAccessibilityViolations } from '../accessibility'
import { renderWithI18n } from '../render'

vi.mock('../../src/modules/asset-pilot/hooks/use-permissions', () => ({
  usePermissions: () => ({ view: true, operate: true, admin: true }),
}))

describe('dead delivery retry accessibility', () => {
  it('has no detectable violations for a requeued delivery result', async () => {
    const delivery = {
      deliveryId: 'd'.repeat(64),
      operationId: 91,
      deliveryKey: 'observer:failure',
      observerId: 'search-index-observer',
      outcome: 'failure' as const,
      attempts: 5,
      lastError: 'Search service unavailable',
      updatedAt: '2026-07-15T10:00:00+00:00',
      fingerprint: 'f'.repeat(64),
    }
    vi.spyOn(assetPilotApi, 'previewDeliveryRetry').mockResolvedValue({
      applied: false,
      planToken: 'signed-delivery-plan',
      count: 1,
      deliveries: [delivery],
    })
    vi.spyOn(assetPilotApi, 'applyDeliveryRetry').mockResolvedValue({
      applied: true,
      planToken: null,
      count: 1,
      deliveries: [delivery],
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(<DeliveryRetryPanel />)

    await user.click(screen.getByRole('button', { name: 'Review dead deliveries' }))
    await user.click(await screen.findByRole('button', { name: 'Requeue reviewed deliveries' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Requeue reviewed deliveries' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Requeued 1 delivery record(s)')
    await expectNoAccessibilityViolations(container)
  })
})
