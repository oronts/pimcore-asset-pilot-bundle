import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import type { DeliveryRetryResponse } from '../../types'
import { DeliveryRetryPanel } from './delivery-retry-panel'

const permissions = vi.hoisted(() => ({ admin: true }))

vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => permissions }))

const deliveryId = 'd'.repeat(64)
const fingerprint = 'f'.repeat(64)
const preview: DeliveryRetryResponse = {
  applied: false,
  planToken: 'signed-delivery-plan',
  count: 1,
  deliveries: [{
    deliveryId,
    operationId: 91,
    deliveryKey: 'observer:success',
    observerId: 'search-index-observer',
    outcome: 'success',
    attempts: 5,
    lastError: 'Search service unavailable',
    updatedAt: '2026-07-15T10:00:00+00:00',
    fingerprint,
  }],
}

afterEach(() => {
  vi.restoreAllMocks()
  permissions.admin = true
})

describe('DeliveryRetryPanel', () => {
  it('is not exposed without admin permission', () => {
    permissions.admin = false

    renderWithI18n(<DeliveryRetryPanel />)

    expect(screen.queryByRole('heading', { name: 'Dead delivery retry' })).not.toBeInTheDocument()
  })

  it('renders every reviewed delivery field and requeues the exact signed scope', async () => {
    const previewRequest = vi.spyOn(assetPilotApi, 'previewDeliveryRetry').mockResolvedValue(preview)
    const applyRequest = vi.spyOn(assetPilotApi, 'applyDeliveryRetry').mockResolvedValue({ ...preview, applied: true, planToken: null })
    const user = userEvent.setup()

    renderWithI18n(<DeliveryRetryPanel />)
    const limit = screen.getByRole('spinbutton', { name: 'Delivery review limit' })
    await user.clear(limit)
    await user.type(limit, '25')
    await user.click(screen.getByRole('button', { name: 'Review dead deliveries' }))

    expect(previewRequest).toHaveBeenCalledWith(25, expect.any(AbortSignal))
    const reviewedTable = await screen.findByRole('table', { name: 'Reviewed dead deliveries' })
    for (const value of [deliveryId, '91', 'observer:success', 'search-index-observer', 'Success event', '5', 'Search service unavailable', fingerprint]) {
      expect(within(reviewedTable).getByText(value)).toBeInTheDocument()
    }
    expect(within(reviewedTable).getByText('2026-07-15T10:00:00+00:00')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Requeue reviewed deliveries' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Requeue reviewed dead deliveries' })
    expect(dialog).toHaveAccessibleDescription(expect.stringContaining('exactly 1 reviewed delivery record(s) using review limit 25'))
    await user.click(within(dialog).getByRole('button', { name: 'Requeue reviewed deliveries' }))

    expect(applyRequest).toHaveBeenCalledWith(25, 'signed-delivery-plan', expect.any(AbortSignal))
    expect(await screen.findByRole('status')).toHaveTextContent('Requeued 1 delivery record(s)')
    expect(screen.getByRole('table', { name: 'Requeued dead deliveries' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Requeue reviewed deliveries' })).not.toBeInTheDocument()
  })

  it('invalidates a stale signed delivery review', async () => {
    vi.spyOn(assetPilotApi, 'previewDeliveryRetry').mockResolvedValue(preview)
    vi.spyOn(assetPilotApi, 'applyDeliveryRetry').mockRejectedValue(new ApiError('stale', 409))
    const user = userEvent.setup()

    renderWithI18n(<DeliveryRetryPanel />)
    await user.click(screen.getByRole('button', { name: 'Review dead deliveries' }))
    await user.click(await screen.findByRole('button', { name: 'Requeue reviewed deliveries' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Requeue reviewed deliveries' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('changed, expired, or were already requeued')
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Requeue reviewed deliveries' })).not.toBeInTheDocument()
  })

  it('rejects a non-empty delivery review without a usable token', async () => {
    vi.spyOn(assetPilotApi, 'previewDeliveryRetry').mockResolvedValue({ ...preview, planToken: '   ' })
    const user = userEvent.setup()

    renderWithI18n(<DeliveryRetryPanel />)
    await user.click(screen.getByRole('button', { name: 'Review dead deliveries' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('usable signed delivery review')
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
