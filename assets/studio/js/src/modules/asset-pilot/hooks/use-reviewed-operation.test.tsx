import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../services/api'
import { requireOperations, useReviewedOperation } from './use-reviewed-operation'

const toast = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('./use-toast', () => ({ useToast: () => toast }))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))

interface Plan { planToken: string | null; folder: string }

const validPreview = { dryRun: true, operations: [{ assetId: 1 }], planToken: 'tok', folder: '/x' }

describe('useReviewedOperation', () => {
  it('reviews a valid preview into a confirmed plan', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))

    await act(async () => {
      await result.current.review(async () => validPreview, r => ({ planToken: r.planToken ?? null, folder: '/x' }))
    })

    expect(result.current.reviewedPlan).toEqual({ planToken: 'tok', folder: '/x' })
    expect(result.current.confirming).toBe(true)
    expect(result.current.running).toBe(false)
  })

  it('rejects a preview missing its plan token and surfaces an error without a plan', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))

    await act(async () => {
      await result.current.review(async () => ({ dryRun: true, operations: [], planToken: '' }), r => ({ planToken: r.planToken ?? null, folder: '/x' }))
    })

    expect(result.current.reviewedPlan).toBeNull()
    expect(result.current.confirming).toBe(false)
    expect(toast.error).toHaveBeenCalled()
  })

  it('derives its messages from the namespace and applies the injected preview validity rule', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.bulk', validatePreview: requireOperations }))

    await act(async () => {
      await result.current.review(async () => ({ dryRun: true, planToken: 'tok' }), r => ({ planToken: r.planToken ?? null, folder: '/x' }))
    })

    expect(result.current.reviewedPlan).toBeNull()
    expect(toast.error).toHaveBeenCalledWith('asset-pilot.bulk.preview-invalid')
  })

  it('defers confirmation when confirmImmediately is false, then confirm() opens it', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))

    await act(async () => {
      await result.current.review(async () => validPreview, r => ({ planToken: r.planToken ?? null, folder: '/x' }), false)
    })
    expect(result.current.reviewedPlan).not.toBeNull()
    expect(result.current.confirming).toBe(false)

    act(() => { result.current.confirm() })
    expect(result.current.confirming).toBe(true)
  })

  it('applies the reviewed plan, captures the run id, and clears the plan', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))
    await act(async () => {
      await result.current.review(async () => validPreview, r => ({ planToken: r.planToken ?? null, folder: '/x' }))
    })
    const onApplied = vi.fn()

    await act(async () => {
      await result.current.apply(async plan => { expect(plan.planToken).toBe('tok'); return { runId: 'run-9', failed: 0 } }, onApplied)
    })

    expect(result.current.runId).toBe('run-9')
    expect(onApplied).toHaveBeenCalledWith({ runId: 'run-9', failed: 0 }, expect.objectContaining({ planToken: 'tok' }))
    expect(result.current.reviewedPlan).toBeNull()
    expect(result.current.confirming).toBe(false)
  })

  it('maps a 409 apply into the stale-plan warning and clears the plan', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))
    await act(async () => {
      await result.current.review(async () => validPreview, r => ({ planToken: r.planToken ?? null, folder: '/x' }))
    })

    await act(async () => {
      await result.current.apply(async () => { throw new ApiError('stale', 409) }, vi.fn())
    })

    expect(toast.warning).toHaveBeenCalledWith('asset-pilot.operations.plan-stale')
    expect(result.current.reviewedPlan).toBeNull()
  })

  it('latest-wins: a superseded review does not overwrite the newer plan', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))
    let releaseSlow: (v: typeof validPreview) => void = () => {}
    const slow = new Promise<typeof validPreview>(resolve => { releaseSlow = resolve })

    let firstDone: Promise<void>
    act(() => {
      firstDone = result.current.review(async () => slow, r => ({ planToken: r.planToken ?? null, folder: '/slow' }))
    })
    await act(async () => {
      await result.current.review(async () => ({ ...validPreview, folder: '/fast' }), r => ({ planToken: r.planToken ?? null, folder: '/fast' }))
    })
    await act(async () => {
      releaseSlow(validPreview)
      await firstDone
    })

    await waitFor(() => expect(result.current.reviewedPlan?.folder).toBe('/fast'))
  })

  it('passes an abort signal to the preview and apply callbacks', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))
    let previewSignal: AbortSignal | null = null
    let applySignal: AbortSignal | null = null

    await act(async () => {
      await result.current.review(async signal => { previewSignal = signal; return validPreview }, r => ({ planToken: r.planToken ?? null, folder: '/x' }))
    })
    await act(async () => {
      await result.current.apply(async (_plan, signal) => { applySignal = signal; return { runId: 'r' } }, vi.fn())
    })

    expect(previewSignal).toBeInstanceOf(AbortSignal)
    expect(applySignal).toBeInstanceOf(AbortSignal)
  })

  it('aborts the in-flight request when a newer review supersedes it', async () => {
    const { result } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))
    let firstAborted = false
    let releaseSlow: (v: typeof validPreview) => void = () => {}
    const slow = new Promise<typeof validPreview>(resolve => { releaseSlow = resolve })

    let firstDone: Promise<void>
    act(() => {
      firstDone = result.current.review(async signal => {
        signal.addEventListener('abort', () => { firstAborted = true })
        return slow
      }, r => ({ planToken: r.planToken ?? null, folder: '/slow' }))
    })
    await act(async () => {
      await result.current.review(async () => ({ ...validPreview, folder: '/fast' }), r => ({ planToken: r.planToken ?? null, folder: '/fast' }))
    })

    expect(firstAborted).toBe(true)

    await act(async () => { releaseSlow(validPreview); await firstDone })
    expect(result.current.reviewedPlan?.folder).toBe('/fast')
  })

  it('does not invoke onApplied after the component unmounts mid-apply', async () => {
    const { result, unmount } = renderHook(() => useReviewedOperation<Plan>({ messageNamespace: 'asset-pilot.operations' }))
    await act(async () => {
      await result.current.review(async () => validPreview, r => ({ planToken: r.planToken ?? null, folder: '/x' }), false)
    })

    let releaseApply: (v: { runId: string }) => void = () => {}
    const pendingApply = new Promise<{ runId: string }>(resolve => { releaseApply = resolve })
    const onApplied = vi.fn()

    let applyDone: Promise<void>
    act(() => { applyDone = result.current.apply(async () => pendingApply, onApplied) })
    unmount()
    await act(async () => { releaseApply({ runId: 'r1' }); await applyDone })

    expect(onApplied).not.toHaveBeenCalled()
  })
})
