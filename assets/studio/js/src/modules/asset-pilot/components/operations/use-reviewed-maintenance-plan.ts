import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../../services/api'

interface SignedReviewResponse {
  applied: boolean
  planToken: string | null
}

export interface ReviewedMaintenancePlan<T extends SignedReviewResponse> {
  limit: number
  response: T
}

interface ReviewedMaintenanceMessages {
  previewInvalid: string
  applyInvalid: string
  previewFailed: (message: string) => string
  applyFailed: (message: string) => string
  stale: string
  unknownError: string
}

interface ReviewedMaintenanceOptions<T extends SignedReviewResponse> {
  previewRequest: (limit: number, signal: AbortSignal) => Promise<T>
  applyRequest: (limit: number, planToken: string, signal: AbortSignal) => Promise<T>
  isValidResponse: (value: unknown, applied: boolean) => value is T
  hasSameTargets: (reviewed: T, applied: T) => boolean
  messages: ReviewedMaintenanceMessages
}

export function useReviewedMaintenancePlan<T extends SignedReviewResponse>(options: ReviewedMaintenanceOptions<T>) {
  const request = useRef<AbortController | null>(null)
  const [limit, setLimit] = useState('100')
  const [action, setAction] = useState<'preview' | 'apply' | null>(null)
  const [reviewed, setReviewed] = useState<ReviewedMaintenancePlan<T> | null>(null)
  const [applied, setApplied] = useState<T | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const numericLimit = Number(limit)
  const limitIsValid = /^\d+$/.test(limit) && Number.isInteger(numericLimit) && numericLimit >= 1 && numericLimit <= 1000

  useEffect(() => () => request.current?.abort(), [])

  const clearResult = (): void => {
    setReviewed(null)
    setApplied(null)
    setConfirming(false)
    setError(null)
  }

  const changeLimit = (value: string): void => {
    setLimit(value)
    clearResult()
  }

  const preview = async (): Promise<void> => {
    if (!limitIsValid) return
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setAction('preview')
    clearResult()

    try {
      const response = await options.previewRequest(numericLimit, controller.signal)
      if (controller.signal.aborted) return
      if (!options.isValidResponse(response, false)) throw new Error(options.messages.previewInvalid)
      setReviewed({ limit: numericLimit, response })
    } catch (caught) {
      if (!(caught instanceof Error && caught.name === 'AbortError')) {
        const message = caught instanceof Error ? caught.message : options.messages.unknownError
        setError(options.messages.previewFailed(message))
      }
    } finally {
      if (!controller.signal.aborted) setAction(null)
      if (request.current === controller) request.current = null
    }
  }

  const apply = async (): Promise<void> => {
    if (reviewed?.response.planToken == null) return
    const currentReview = reviewed
    const planToken = reviewed.response.planToken
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setAction('apply')
    setError(null)

    try {
      const response = await options.applyRequest(currentReview.limit, planToken, controller.signal)
      if (controller.signal.aborted) return
      if (!options.isValidResponse(response, true) || !options.hasSameTargets(currentReview.response, response)) {
        throw new Error(options.messages.applyInvalid)
      }
      setApplied(response)
      setReviewed(null)
    } catch (caught) {
      if (caught instanceof Error && caught.name === 'AbortError') return
      if (caught instanceof ApiError && caught.status === 409) {
        setReviewed(null)
        setError(options.messages.stale)
      } else {
        const message = caught instanceof Error ? caught.message : options.messages.unknownError
        setError(options.messages.applyFailed(message))
      }
    } finally {
      if (!controller.signal.aborted) {
        setAction(null)
        setConfirming(false)
      }
      if (request.current === controller) request.current = null
    }
  }

  return {
    limit,
    numericLimit,
    limitIsValid,
    action,
    reviewed,
    applied,
    confirming,
    error,
    changeLimit,
    preview,
    apply,
    setConfirming,
  }
}
