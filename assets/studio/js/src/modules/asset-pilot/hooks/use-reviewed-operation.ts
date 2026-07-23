import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../services/api'
import { useToast } from './use-toast'

interface ReviewedPreview {
  dryRun?: boolean
  operations?: unknown[] | null
  planToken?: string | null
}

interface ReviewedPlanBase {
  planToken: string | null
}

export interface ReviewedOperation<TPlan extends ReviewedPlanBase> {
  running: boolean
  confirming: boolean
  reviewedPlan: TPlan | null
  runId: string | null
  review: <R extends ReviewedPreview>(preview: (signal: AbortSignal) => Promise<R>, toPlan: (result: R) => TPlan, confirmImmediately?: boolean) => Promise<void>
  confirm: () => void
  apply: <A extends { runId?: string | null }>(applyCall: (plan: TPlan & { planToken: string }, signal: AbortSignal) => Promise<A>, onApplied: (result: A, plan: TPlan) => void) => Promise<void>
  clear: () => void
  cancel: () => void
  setRunId: (id: string | null) => void
}

/**
 * The shared preview -> signed-token -> apply lifecycle behind the reviewed operation forms (organize,
 * bulk organize, reorganize, replay). It owns running/confirming/reviewedPlan/runId, the latest-wins
 * guard on preview, plan-token validation, and 409 stale-plan handling, so each form supplies only its
 * own request and response adapters. Centralizing the security-sensitive apply ceremony keeps it from
 * drifting between forms.
 */
export function useReviewedOperation<TPlan extends ReviewedPlanBase>(
  options: { fallbackErrorKey?: string } = {},
): ReviewedOperation<TPlan> {
  const fallbackErrorKey = options.fallbackErrorKey ?? 'asset-pilot.common.unknown-error'
  const { t } = useTranslation()
  const toast = useToast()
  const [running, setRunning] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [reviewedPlan, setReviewedPlan] = useState<TPlan | null>(null)
  const [runId, setRunId] = useState<string | null>(null)
  const request = useRef<AbortController | null>(null)

  // Abort any in-flight preview/apply when the owning component unmounts so a late resolve cannot
  // set state, invoke onApplied, or toast for a screen that no longer owns the request.
  useEffect(() => () => request.current?.abort(), [])

  const clear = (): void => {
    // Abort so an in-flight preview/apply cannot land a plan after the inputs changed, and drop busy
    // state here: the aborted request's finally skips setRunning(false) (that guard is for supersession
    // by a newer request), so a user-initiated clear must release the form itself.
    request.current?.abort()
    request.current = null
    setRunning(false)
    setReviewedPlan(null)
    setConfirming(false)
    setRunId(null)
  }

  const review = async <R extends ReviewedPreview>(
    preview: (signal: AbortSignal) => Promise<R>,
    toPlan: (result: R) => TPlan,
    confirmImmediately = true,
  ): Promise<void> => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setRunning(true)
    setReviewedPlan(null)
    setConfirming(false)
    setRunId(null)
    try {
      const result = await preview(controller.signal)
      if (controller.signal.aborted) return
      if (result.dryRun !== true || result.operations == null || result.planToken == null || result.planToken === '') {
        throw new Error(t('asset-pilot.operations.preview-invalid'))
      }
      setReviewedPlan(toPlan(result))
      if (confirmImmediately) setConfirming(true)
    } catch (error) {
      if (controller.signal.aborted || (error instanceof Error && error.name === 'AbortError')) return
      toast.error(error instanceof Error ? error.message : t(fallbackErrorKey))
    } finally {
      if (!controller.signal.aborted) setRunning(false)
      if (request.current === controller) request.current = null
    }
  }

  const confirm = (): void => setConfirming(true)

  const apply = async <A extends { runId?: string | null }>(
    applyCall: (plan: TPlan & { planToken: string }, signal: AbortSignal) => Promise<A>,
    onApplied: (result: A, plan: TPlan) => void,
  ): Promise<void> => {
    if (reviewedPlan == null || reviewedPlan.planToken == null) return
    const plan = { ...reviewedPlan, planToken: reviewedPlan.planToken }
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setRunning(true)
    setRunId(null)
    try {
      const result = await applyCall(plan, controller.signal)
      if (controller.signal.aborted) return
      if (result.runId != null) setRunId(result.runId)
      onApplied(result, plan)
      setReviewedPlan(null)
    } catch (error) {
      if (controller.signal.aborted || (error instanceof Error && error.name === 'AbortError')) return
      setReviewedPlan(null)
      if (error instanceof ApiError && error.status === 409) toast.warning(t('asset-pilot.operations.plan-stale'))
      else toast.error(error instanceof Error ? error.message : t(fallbackErrorKey))
    } finally {
      if (!controller.signal.aborted) {
        setRunning(false)
        setConfirming(false)
      }
      if (request.current === controller) request.current = null
    }
  }

  const cancel = (): void => {
    request.current?.abort()
    request.current = null
    setRunning(false)
    setConfirming(false)
  }

  return { running, confirming, reviewedPlan, runId, review, confirm, apply, clear, cancel, setRunId }
}
