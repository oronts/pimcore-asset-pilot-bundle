import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@pimcore/studio-ui-bundle/api', () => ({
  getPrefix: () => '/pimcore-studio/api',
}))

import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import type { HealResponse } from '../../types'
import { HealModal } from './heal-modal'

const preview: HealResponse = {
  dryRun: true,
  planToken: 'heal-plan',
  results: [
    {
      assetId: 7,
      outcome: 'healed',
      toVersion: 4,
      checker: 'render',
      reason: 'Version 5 is corrupt',
      observerWarnings: [],
    },
    {
      assetId: 8,
      outcome: 'unrecoverable',
      toVersion: null,
      checker: 'render',
      reason: 'No renderable version',
      observerWarnings: ['Observer warning'],
    },
  ],
}

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

afterEach(() => vi.restoreAllMocks())

describe('HealModal', () => {
  it('applies only the exact sorted selection and token returned by review', async () => {
    const user = userEvent.setup()
    const previewRequest = vi.spyOn(assetPilotApi, 'previewHealAssets').mockResolvedValue(preview)
    const applyRequest = vi.spyOn(assetPilotApi, 'applyHealAssets').mockResolvedValue({
      ...preview,
      dryRun: false,
      planToken: null,
    })
    const onHealed = vi.fn()

    renderWithI18n(<HealModal ids={[8, 7]} canApply onClose={vi.fn()} onHealed={onHealed} />)

    expect(screen.queryByRole('button', { name: 'Apply reviewed heal' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Review heal plan' }))

    const apply = await screen.findByRole('button', { name: 'Apply reviewed heal' })
    expect(previewRequest).toHaveBeenCalledWith([7, 8], expect.any(AbortSignal))
    expect(screen.getByText('Healed')).toBeInTheDocument()
    expect(screen.getByText('Unrecoverable')).toBeInTheDocument()
    expect(screen.getByText('v4')).toBeInTheDocument()
    expect(screen.getByText('Version 5 is corrupt')).toBeInTheDocument()
    expect(screen.getByText('No renderable version Observer warning')).toBeInTheDocument()

    await user.click(apply)

    await waitFor(() => expect(onHealed).toHaveBeenCalledOnce())
    expect(applyRequest).toHaveBeenCalledWith([7, 8], 'heal-plan', expect.any(AbortSignal))
  })

  it('re-enables the modal when the selection changes during an in-flight apply', async () => {
    const user = userEvent.setup()
    vi.spyOn(assetPilotApi, 'previewHealAssets').mockResolvedValue(preview)
    const pendingApply = deferred<HealResponse>()
    vi.spyOn(assetPilotApi, 'applyHealAssets').mockReturnValue(pendingApply.promise)
    const { rerender } = renderWithI18n(<HealModal ids={[7]} canApply onClose={vi.fn()} onHealed={vi.fn()} />)

    await user.click(screen.getByRole('button', { name: 'Review heal plan' }))
    await user.click(await screen.findByRole('button', { name: 'Apply reviewed heal' }))
    expect(screen.getByRole('button', { name: 'Review heal plan' })).toBeDisabled()

    rerender(<HealModal ids={[9, 8]} canApply onClose={vi.fn()} onHealed={vi.fn()} />)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review heal plan' })).toBeEnabled())
  })

  it('invalidates the reviewed plan when the asset selection changes', async () => {
    const user = userEvent.setup()
    vi.spyOn(assetPilotApi, 'previewHealAssets').mockResolvedValue(preview)
    const onClose = vi.fn()
    const onHealed = vi.fn()
    const view = renderWithI18n(<HealModal ids={[7, 8]} canApply onClose={onClose} onHealed={onHealed} />)

    await user.click(screen.getByRole('button', { name: 'Review heal plan' }))
    await screen.findByRole('button', { name: 'Apply reviewed heal' })

    view.rerender(<HealModal ids={[9]} canApply onClose={onClose} onHealed={onHealed} />)

    expect(await screen.findByRole('status')).toHaveTextContent('The asset selection, filters, or page changed. Review the heal plan again.')
    expect(screen.queryByRole('button', { name: 'Apply reviewed heal' })).not.toBeInTheDocument()
    expect(screen.queryByText('Version 5 is corrupt')).not.toBeInTheDocument()
  })

  it('ignores an in-flight review after the asset selection changes', async () => {
    const pending = deferred<HealResponse>()
    vi.spyOn(assetPilotApi, 'previewHealAssets').mockReturnValue(pending.promise)
    const user = userEvent.setup()
    const props = { canApply: true, onClose: vi.fn(), onHealed: vi.fn() }
    const view = renderWithI18n(<HealModal {...props} ids={[7, 8]} />)

    await user.click(screen.getByRole('button', { name: 'Review heal plan' }))
    view.rerender(<HealModal {...props} ids={[9]} />)
    await act(async () => pending.resolve(preview))

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Apply reviewed heal' })).not.toBeInTheDocument())
    expect(screen.queryByText('Version 5 is corrupt')).not.toBeInTheDocument()
  })

  it('clears a stale plan and explains a 409 conflict', async () => {
    const user = userEvent.setup()
    vi.spyOn(assetPilotApi, 'previewHealAssets').mockResolvedValue(preview)
    vi.spyOn(assetPilotApi, 'applyHealAssets').mockRejectedValue(new ApiError('Plan is stale', 409))

    renderWithI18n(<HealModal ids={[7, 8]} canApply onClose={vi.fn()} onHealed={vi.fn()} />)

    await user.click(screen.getByRole('button', { name: 'Review heal plan' }))
    await user.click(await screen.findByRole('button', { name: 'Apply reviewed heal' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The selected assets or their versions changed after review. Review the heal plan again.')
    expect(screen.queryByRole('button', { name: 'Apply reviewed heal' })).not.toBeInTheDocument()
    expect(screen.queryByText('Version 5 is corrupt')).not.toBeInTheDocument()
  })

  it('allows review without exposing apply when operate permission is absent', async () => {
    const user = userEvent.setup()
    vi.spyOn(assetPilotApi, 'previewHealAssets').mockResolvedValue(preview)

    renderWithI18n(<HealModal ids={[7]} canApply={false} onClose={vi.fn()} onHealed={vi.fn()} />)

    await user.click(screen.getByRole('button', { name: 'Review heal plan' }))
    await screen.findByText('Version 5 is corrupt')

    expect(screen.queryByRole('button', { name: 'Apply reviewed heal' })).not.toBeInTheDocument()
    expect(screen.getByText('Healing requires operate permission.')).toBeInTheDocument()
  })
})
