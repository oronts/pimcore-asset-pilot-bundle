import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import type { PlannedBulkActionResult } from '../../types'
import { PropertyForm } from './property-form'
import { TagPicker } from './tag-picker'

vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useTags: () => ({
    data: { items: [{ id: 3, name: 'Campaign', parentId: null, path: '/Campaign' }], total: 1, page: 1, limit: 50, pages: 1 },
    loading: false,
    error: null,
    refetch: vi.fn(),
  }),
}))

const preview = (overrides: Partial<PlannedBulkActionResult> = {}): PlannedBulkActionResult => ({
  failed: 1,
  errors: { 8: 'Asset is locked' },
  observerWarnings: [],
  dryRun: true,
  planToken: 'reviewed-plan',
  eligible: 1,
  ...overrides,
})

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

describe('metadata immutable plans', () => {
  beforeEach(() => vi.restoreAllMocks())

  it('reviews eligibility and applies the exact sorted tag snapshot', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkTagAssets').mockResolvedValue(preview())
    vi.spyOn(assetPilotApi, 'applyBulkTagAssets').mockResolvedValue(preview({ tagged: 1, dryRun: false, planToken: null }))
    const done = vi.fn()
    const user = userEvent.setup()
    renderWithI18n(<TagPicker assetIds={[8, 7]} onDone={done} onCancel={vi.fn()} />)

    await user.click(screen.getByRole('checkbox', { name: '/Campaign' }))
    await user.click(screen.getByRole('button', { name: 'Review Tag Assignment' }))

    const review = await screen.findByRole('status', { name: 'Metadata change review' })
    expect(review).toHaveTextContent('Eligible: 1, blocked: 1')
    expect(review).toHaveTextContent('Asset #8: Asset is locked')
    await user.click(screen.getByRole('button', { name: 'Apply Reviewed Tags' }))

    expect(assetPilotApi.previewBulkTagAssets).toHaveBeenCalledWith([7, 8], [3], false, expect.any(AbortSignal))
    expect(assetPilotApi.applyBulkTagAssets).toHaveBeenCalledWith([7, 8], [3], false, 'reviewed-plan', expect.any(AbortSignal))
    expect(done).toHaveBeenCalledWith(expect.objectContaining({ severity: 'warning' }))
  })

  it('invalidates tag review when mutation inputs or the asset selection change', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkTagAssets').mockResolvedValue(preview({ failed: 0, errors: {}, eligible: 2 }))
    const user = userEvent.setup()
    const props = { onDone: vi.fn(), onCancel: vi.fn() }
    const view = renderWithI18n(<TagPicker {...props} assetIds={[7, 8]} />)

    await user.click(screen.getByRole('checkbox', { name: '/Campaign' }))
    await user.click(screen.getByRole('button', { name: 'Review Tag Assignment' }))
    expect(await screen.findByRole('button', { name: 'Apply Reviewed Tags' })).toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: 'Replace existing tags' }))
    expect(screen.queryByRole('button', { name: 'Apply Reviewed Tags' })).not.toBeInTheDocument()
    expect(screen.getByText('Inputs or asset selection changed. Review again before applying.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Review Tag Assignment' }))
    expect(await screen.findByRole('button', { name: 'Apply Reviewed Tags' })).toBeInTheDocument()
    view.rerender(<TagPicker {...props} assetIds={[7]} />)
    expect(screen.queryByRole('button', { name: 'Apply Reviewed Tags' })).not.toBeInTheDocument()
  })

  it('clears a stale tag plan and explains that a new review is required', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkTagAssets').mockResolvedValue(preview({ failed: 0, errors: {}, eligible: 1 }))
    vi.spyOn(assetPilotApi, 'applyBulkTagAssets').mockRejectedValue(new ApiError('stale', 409))
    const user = userEvent.setup()
    renderWithI18n(<TagPicker assetIds={[7]} onDone={vi.fn()} onCancel={vi.fn()} />)

    await user.click(screen.getByRole('checkbox', { name: '/Campaign' }))
    await user.click(screen.getByRole('button', { name: 'Review Tag Assignment' }))
    await user.click(await screen.findByRole('button', { name: 'Apply Reviewed Tags' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The selected assets or metadata changed after review. Review again before applying.')
    expect(screen.queryByRole('button', { name: 'Apply Reviewed Tags' })).not.toBeInTheDocument()
  })

  it('ignores an in-flight tag review after the asset selection changes', async () => {
    const pending = deferred<PlannedBulkActionResult>()
    vi.spyOn(assetPilotApi, 'previewBulkTagAssets').mockReturnValue(pending.promise)
    const user = userEvent.setup()
    const props = { onDone: vi.fn(), onCancel: vi.fn() }
    const view = renderWithI18n(<TagPicker {...props} assetIds={[7, 8]} />)

    await user.click(screen.getByRole('checkbox', { name: '/Campaign' }))
    await user.click(screen.getByRole('button', { name: 'Review Tag Assignment' }))
    view.rerender(<TagPicker {...props} assetIds={[7]} />)
    await act(async () => pending.resolve(preview({ failed: 0, errors: {}, eligible: 2 })))

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Apply Reviewed Tags' })).not.toBeInTheDocument())
  })

  it('invalidates edited property inputs then applies the newly reviewed exact snapshot', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkSetProperty')
      .mockResolvedValueOnce(preview({ failed: 0, errors: {}, eligible: 2, planToken: 'old-plan' }))
      .mockResolvedValueOnce(preview({ failed: 0, errors: {}, eligible: 2, planToken: 'new-plan' }))
    vi.spyOn(assetPilotApi, 'applyBulkSetProperty').mockResolvedValue(preview({ updated: 2, failed: 0, errors: {}, eligible: 2, dryRun: false, planToken: null }))
    const user = userEvent.setup()
    renderWithI18n(<PropertyForm assetIds={[8, 7]} onDone={vi.fn()} onCancel={vi.fn()} />)

    await user.type(screen.getByRole('textbox', { name: 'Property Name' }), 'source')
    await user.type(screen.getByRole('textbox', { name: 'Value' }), 'catalog')
    await user.click(screen.getByRole('button', { name: 'Review Property Change' }))
    expect(await screen.findByRole('button', { name: 'Apply Reviewed Property' })).toBeInTheDocument()

    await user.type(screen.getByRole('textbox', { name: 'Value' }), '-2026')
    expect(screen.queryByRole('button', { name: 'Apply Reviewed Property' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Review Property Change' }))
    await user.click(await screen.findByRole('button', { name: 'Apply Reviewed Property' }))

    expect(assetPilotApi.previewBulkSetProperty).toHaveBeenLastCalledWith([7, 8], 'source', 'text', 'catalog-2026', expect.any(AbortSignal))
    expect(assetPilotApi.applyBulkSetProperty).toHaveBeenCalledWith([7, 8], 'source', 'text', 'catalog-2026', 'new-plan', expect.any(AbortSignal))
  })

  it('aborts an in-flight property review when the form unmounts', async () => {
    const pending = deferred<PlannedBulkActionResult>()
    let signal: AbortSignal | undefined
    vi.spyOn(assetPilotApi, 'previewBulkSetProperty').mockImplementation((_ids, _name, _type, _data, given) => {
      signal = given
      return pending.promise
    })
    const user = userEvent.setup()
    const view = renderWithI18n(<PropertyForm assetIds={[7]} onDone={vi.fn()} onCancel={vi.fn()} />)

    await user.type(screen.getByRole('textbox', { name: 'Property Name' }), 'source')
    await user.click(screen.getByRole('button', { name: 'Review Property Change' }))
    expect(signal?.aborted).toBe(false)

    view.unmount()
    expect(signal?.aborted).toBe(true)
  })

  it('does not complete a tag apply that resolves after the form unmounts', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkTagAssets').mockResolvedValue(preview({ failed: 0, errors: {}, eligible: 1 }))
    const pendingApply = deferred<PlannedBulkActionResult>()
    let applySignal: AbortSignal | undefined
    vi.spyOn(assetPilotApi, 'applyBulkTagAssets').mockImplementation((_ids, _tags, _replace, _token, given) => {
      applySignal = given
      return pendingApply.promise
    })
    const done = vi.fn()
    const user = userEvent.setup()
    const view = renderWithI18n(<TagPicker assetIds={[7]} onDone={done} onCancel={vi.fn()} />)

    await user.click(screen.getByRole('checkbox', { name: '/Campaign' }))
    await user.click(screen.getByRole('button', { name: 'Review Tag Assignment' }))
    await user.click(await screen.findByRole('button', { name: 'Apply Reviewed Tags' }))
    expect(applySignal?.aborted).toBe(false)

    view.unmount()
    expect(applySignal?.aborted).toBe(true)
    await act(async () => pendingApply.resolve(preview({ tagged: 1, dryRun: false, planToken: null })))
    expect(done).not.toHaveBeenCalled()
  })

  it('ignores an in-flight property review after the asset selection changes', async () => {
    const pending = deferred<PlannedBulkActionResult>()
    vi.spyOn(assetPilotApi, 'previewBulkSetProperty').mockReturnValue(pending.promise)
    const user = userEvent.setup()
    const props = { onDone: vi.fn(), onCancel: vi.fn() }
    const view = renderWithI18n(<PropertyForm {...props} assetIds={[7, 8]} />)

    await user.type(screen.getByRole('textbox', { name: 'Property Name' }), 'source')
    await user.click(screen.getByRole('button', { name: 'Review Property Change' }))
    view.rerender(<PropertyForm {...props} assetIds={[7]} />)
    await act(async () => pending.resolve(preview({ failed: 0, errors: {}, eligible: 2 })))

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Apply Reviewed Property' })).not.toBeInTheDocument())
  })

  it('re-enables the property form when the asset selection changes during an in-flight apply', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkSetProperty').mockResolvedValue(preview({ failed: 0, errors: {}, eligible: 1 }))
    const pendingApply = deferred<PlannedBulkActionResult>()
    vi.spyOn(assetPilotApi, 'applyBulkSetProperty').mockReturnValue(pendingApply.promise)
    const user = userEvent.setup()
    const props = { onDone: vi.fn(), onCancel: vi.fn() }
    const view = renderWithI18n(<PropertyForm {...props} assetIds={[7]} />)

    await user.type(screen.getByRole('textbox', { name: 'Property Name' }), 'source')
    await user.click(screen.getByRole('button', { name: 'Review Property Change' }))
    await user.click(await screen.findByRole('button', { name: 'Apply Reviewed Property' }))
    expect(screen.getByRole('button', { name: 'Review Property Change' })).toBeDisabled()

    view.rerender(<PropertyForm {...props} assetIds={[8, 7]} />)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review Property Change' })).toBeEnabled())
  })
})
