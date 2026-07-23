import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../services/api'
import type { PlannedBulkActionResult } from '../../types'

export interface ReviewedMetadataPlanBase {
  assetSignature: string
  planToken: string
}

export interface ReviewedMetadataPlan<TPlan extends ReviewedMetadataPlanBase> {
  status: 'preview' | 'apply' | null
  reviewedPlan: TPlan | null
  error: string | null
  notice: string | null
  invalidate: () => void
  review: (preview: (signal: AbortSignal) => Promise<PlannedBulkActionResult>, toPlan: (result: PlannedBulkActionResult) => TPlan) => Promise<void>
  apply: (applyCall: (plan: TPlan, signal: AbortSignal) => Promise<PlannedBulkActionResult>, onApplied: (result: PlannedBulkActionResult) => void) => Promise<void>
}

/**
 * The shared preview -> signed-token -> apply lifecycle behind the bulk metadata forms (tag assignment,
 * property change). It owns status/reviewedPlan/error/notice, the asset-selection invalidation, the
 * plan-token validity gate, 409 stale-plan handling, and per-request cancellation, so each form supplies
 * only its request adapters, plan payload, and success summary. Centralizing the security-sensitive apply
 * ceremony keeps it from drifting between the two forms, and threading each request's AbortSignal (plus
 * aborting on unmount) means a late resolve can never land a plan or fire onDone for a form that is gone
 * or whose asset selection has moved on.
 */
export function useReviewedMetadataPlan<TPlan extends ReviewedMetadataPlanBase>(assetSignature: string): ReviewedMetadataPlan<TPlan> {
  const { t } = useTranslation()
  const [status, setStatus] = useState<'preview' | 'apply' | null>(null)
  const [reviewedPlan, setReviewedPlan] = useState<TPlan | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const request = useRef<AbortController | null>(null)
  const previousAssetSignature = useRef(assetSignature)

  useEffect(() => () => request.current?.abort(), [])

  useEffect(() => {
    if (previousAssetSignature.current === assetSignature) return
    previousAssetSignature.current = assetSignature
    request.current?.abort()
    request.current = null
    if (reviewedPlan != null && reviewedPlan.assetSignature !== assetSignature) {
      setReviewedPlan(null)
      setError(null)
      setNotice(t('asset-pilot.management.review-invalidated'))
    }
    setStatus(null)
  }, [assetSignature, reviewedPlan, status, t])

  const invalidate = (): void => {
    request.current?.abort()
    request.current = null
    if (reviewedPlan != null) setNotice(t('asset-pilot.management.review-invalidated'))
    setReviewedPlan(null)
    setError(null)
  }

  const review = async (
    preview: (signal: AbortSignal) => Promise<PlannedBulkActionResult>,
    toPlan: (result: PlannedBulkActionResult) => TPlan,
  ): Promise<void> => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setStatus('preview')
    setError(null)
    setNotice(null)
    try {
      const result = await preview(controller.signal)
      if (controller.signal.aborted) return
      if (result.dryRun !== true || result.planToken == null || result.planToken === '') {
        throw new Error(t('asset-pilot.management.review-invalid-response'))
      }
      setReviewedPlan(toPlan(result))
    } catch (caught) {
      if (controller.signal.aborted || (caught instanceof Error && caught.name === 'AbortError')) return
      setReviewedPlan(null)
      setError(caught instanceof Error ? caught.message : t('asset-pilot.common.unknown-error'))
    } finally {
      if (!controller.signal.aborted) setStatus(null)
      if (request.current === controller) request.current = null
    }
  }

  const apply = async (
    applyCall: (plan: TPlan, signal: AbortSignal) => Promise<PlannedBulkActionResult>,
    onApplied: (result: PlannedBulkActionResult) => void,
  ): Promise<void> => {
    if (reviewedPlan == null) return
    const plan = reviewedPlan
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setStatus('apply')
    setError(null)
    setNotice(null)
    try {
      const result = await applyCall(plan, controller.signal)
      if (controller.signal.aborted) return
      setReviewedPlan(null)
      onApplied(result)
    } catch (caught) {
      if (controller.signal.aborted || (caught instanceof Error && caught.name === 'AbortError')) return
      setReviewedPlan(null)
      setError(caught instanceof ApiError && caught.status === 409
        ? t('asset-pilot.management.review-stale')
        : caught instanceof Error ? caught.message : t('asset-pilot.common.unknown-error'))
    } finally {
      if (!controller.signal.aborted) setStatus(null)
      if (request.current === controller) request.current = null
    }
  }

  return { status, reviewedPlan, error, notice, invalidate, review, apply }
}
