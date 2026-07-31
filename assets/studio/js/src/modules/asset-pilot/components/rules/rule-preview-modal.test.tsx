import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import type { OrganizeResponse } from '../../types'
import { RulePreviewModal } from './rule-preview-modal'

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('../../hooks/use-toast', () => ({ useToast: () => toast }))
vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => ({ operate: true }) }))

const previewResponse = {
  operations: [{
    assetId: 7,
    sourcePath: '/incoming/image.jpg',
    targetPath: '/products/image.jpg',
    ruleName: 'product-assets',
  }],
  planToken: 'actor-bound-preview-token',
}

async function previewAndOpenConfirmation(): Promise<void> {
  const user = userEvent.setup()
  await user.type(screen.getByRole('spinbutton', { name: 'Object ID' }), '42')
  await user.click(screen.getByRole('button', { name: 'Run Preview' }))
  expect(await screen.findByText('/products/image.jpg')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Apply Now' }))
  expect(await screen.findByRole('alertdialog', { name: 'Apply reviewed rule result' })).toBeInTheDocument()
}

describe('RulePreviewModal', () => {
  it('applies the exact actor-bound token returned by the reviewed preview', async () => {
    vi.spyOn(assetPilotApi, 'previewRule').mockResolvedValue(previewResponse)
    const apply = vi.spyOn(assetPilotApi, 'applyRule').mockResolvedValue({ results: [] })
    const onClose = vi.fn()

    renderWithI18n(<RulePreviewModal ruleName="product-assets" onClose={onClose} />)
    await previewAndOpenConfirmation()
    await userEvent.click(screen.getByRole('button', { name: 'Apply Now' }))

    await waitFor(() => expect(apply).toHaveBeenCalledWith('product-assets', 42, 'actor-bound-preview-token', expect.any(AbortSignal)))
    expect(toast.success).toHaveBeenCalledWith('Organization completed successfully')
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('discards a stale preview after the backend rejects its token', async () => {
    vi.spyOn(assetPilotApi, 'previewRule').mockResolvedValue(previewResponse)
    vi.spyOn(assetPilotApi, 'applyRule').mockRejectedValue(new ApiError('Preview plan is stale.', 409))

    renderWithI18n(<RulePreviewModal ruleName="product-assets" onClose={vi.fn()} />)
    await previewAndOpenConfirmation()
    await userEvent.click(screen.getByRole('button', { name: 'Apply Now' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Preview plan is stale.'))
    expect(screen.queryByText('/products/image.jpg')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Run Preview' })).toBeInTheDocument()
  })

  it('rejects a preview response without a signed plan', async () => {
    vi.spyOn(assetPilotApi, 'previewRule').mockResolvedValue({ operations: previewResponse.operations, planToken: '' })
    const user = userEvent.setup()
    renderWithI18n(<RulePreviewModal ruleName="product-assets" onClose={vi.fn()} />)

    await user.type(screen.getByRole('spinbutton', { name: 'Object ID' }), '42')
    await user.click(screen.getByRole('button', { name: 'Run Preview' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The server did not return a usable signed preview.')
    expect(screen.queryByRole('button', { name: 'Apply Now' })).not.toBeInTheDocument()
  })

  it('aborts the apply request and suppresses late effects when unmounted mid-apply', async () => {
    vi.spyOn(assetPilotApi, 'previewRule').mockResolvedValue(previewResponse)
    let rejectApply: (reason: unknown) => void = () => {}
    const apply = vi.spyOn(assetPilotApi, 'applyRule')
      .mockImplementation(() => new Promise<OrganizeResponse>((_resolve, reject) => { rejectApply = reject }))
    const onClose = vi.fn()

    const view = renderWithI18n(<RulePreviewModal ruleName="product-assets" onClose={onClose} />)
    await previewAndOpenConfirmation()
    await userEvent.click(screen.getByRole('button', { name: 'Apply Now' }))

    await waitFor(() => expect(apply).toHaveBeenCalled())
    const applySignal = apply.mock.calls[0]?.[3]
    if (applySignal == null) throw new Error('Expected an apply request signal.')
    expect(applySignal.aborted).toBe(false)

    view.unmount()
    expect(applySignal.aborted).toBe(true)

    // The in-flight request settles with a real (non-abort) error AFTER unmount: the aborted guard must
    // suppress the 409 reset, the error toast, and onClose for the component that no longer owns it.
    rejectApply(new ApiError('Preview plan is stale.', 409))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(toast.error).not.toHaveBeenCalled()
    expect(toast.success).not.toHaveBeenCalled()
    expect(onClose).not.toHaveBeenCalled()
  })
})
