import React, { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError, assetPilotApi } from '../../services/api'
import type { HealResponse } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { modalOverlayStyle, modalSurfaceStyle } from '../shared/modal-styles'

interface HealModalProps {
  ids: number[]
  canApply: boolean
  onClose: () => void
  onHealed: () => void
}

interface ReviewedHealPlan {
  ids: number[]
  signature: string
  planToken: string
  result: HealResponse
}

type HealAction = 'preview' | 'apply'

export const HealModal: React.FC<HealModalProps> = ({ ids, canApply, onClose, onHealed }) => {
  const { t } = useTranslation()
  const normalizedIds = useMemo(() => [...new Set(ids)].sort((a, b) => a - b), [ids])
  const selectionSignature = normalizedIds.join(',')
  const [reviewedPlan, setReviewedPlan] = useState<ReviewedHealPlan | null>(null)
  const [loading, setLoading] = useState<HealAction | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const modalRef = useModalDismiss<HTMLDivElement>(onClose, loading == null)
  const previewVersion = useRef(0)
  const previousSelectionSignature = useRef(selectionSignature)
  const request = useRef<AbortController | null>(null)

  useEffect(() => () => request.current?.abort(), [])

  useEffect(() => {
    if (previousSelectionSignature.current === selectionSignature) return
    previousSelectionSignature.current = selectionSignature
    request.current?.abort()
    request.current = null
    previewVersion.current++
    if (reviewedPlan != null && reviewedPlan.signature !== selectionSignature) {
      setReviewedPlan(null)
      setError(null)
      setNotice(t('asset-pilot.integrity.review-invalidated'))
    }
    setLoading(null)
  }, [loading, reviewedPlan, selectionSignature, t])

  const preview = async (): Promise<void> => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setLoading('preview')
    setReviewedPlan(null)
    setError(null)
    setNotice(null)
    const version = ++previewVersion.current

    try {
      const result = await assetPilotApi.previewHealAssets(normalizedIds, controller.signal)
      if (controller.signal.aborted || version !== previewVersion.current) return
      if (!result.dryRun || result.planToken == null || result.planToken === '') {
        setError(t('asset-pilot.integrity.review-invalid-response'))

        return
      }

      setReviewedPlan({
        ids: normalizedIds,
        signature: selectionSignature,
        planToken: result.planToken,
        result,
      })
    } catch (e) {
      if (controller.signal.aborted || (e instanceof Error && e.name === 'AbortError') || version !== previewVersion.current) return
      setError(e instanceof Error ? e.message : t('asset-pilot.common.unknown-error'))
    } finally {
      if (!controller.signal.aborted && version === previewVersion.current) setLoading(null)
      if (request.current === controller) request.current = null
    }
  }

  const apply = async (): Promise<void> => {
    if (reviewedPlan == null) return

    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setLoading('apply')
    setError(null)
    setNotice(null)

    try {
      await assetPilotApi.applyHealAssets(reviewedPlan.ids, reviewedPlan.planToken, controller.signal)
      if (controller.signal.aborted) return
      setReviewedPlan(null)
      onHealed()
    } catch (e) {
      if (controller.signal.aborted || (e instanceof Error && e.name === 'AbortError')) return
      setReviewedPlan(null)
      if (e instanceof ApiError && e.status === 409) {
        setError(t('asset-pilot.integrity.review-stale'))
      } else {
        setError(e instanceof Error ? e.message : t('asset-pilot.common.unknown-error'))
      }
    } finally {
      if (!controller.signal.aborted) setLoading(null)
      if (request.current === controller) request.current = null
    }
  }

  const isLoading = loading != null

  return (
    <div role="presentation" style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget && !isLoading) onClose() }}>
      <div ref={modalRef} role="dialog" aria-modal="true" aria-label={t('asset-pilot.integrity.heal-title')} tabIndex={-1} style={modalStyle}>
        <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.integrity.heal-title')}</h3>
        <p style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', margin: '0 0 16px' }}>{t('asset-pilot.integrity.heal-desc', { count: normalizedIds.length })}</p>

        {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13, marginBottom: 12 }}>{error}</p>}
        {notice != null && <p role="status" style={{ color: 'var(--ap-color-text-secondary)', fontSize: 13, marginBottom: 12 }}>{notice}</p>}

        {reviewedPlan != null && (
          <div style={{ background: 'var(--ap-color-fill-alter)', borderRadius: 6, padding: 12, marginBottom: 16, maxHeight: 220, overflow: 'auto' }}>
            <p style={{ margin: '0 0 4px', fontSize: 'var(--ap-font-size)', fontWeight: 600 }}>
              {t('asset-pilot.integrity.preview-result')}
            </p>
            <p role="status" style={{ margin: '0 0 8px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>
              {t('asset-pilot.integrity.review-ready')}
            </p>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--ap-color-border-secondary)' }}>
                  <th style={resThStyle}>{t('asset-pilot.columns.asset-id')}</th>
                  <th style={resThStyle}>{t('asset-pilot.columns.outcome')}</th>
                  <th style={resThStyle}>{t('asset-pilot.integrity.to-version')}</th>
                  <th style={resThStyle}>{t('asset-pilot.columns.reason')}</th>
                </tr>
              </thead>
              <tbody>
                {reviewedPlan.result.results.map(result => (
                  <tr key={result.assetId}>
                    <td style={resTdStyle}>#{result.assetId}</td>
                    <td style={resTdStyle}>{t(`asset-pilot.integrity.outcome.${result.outcome}`, { defaultValue: result.outcome })}</td>
                    <td style={resTdStyle}>{result.toVersion != null ? `v${result.toVersion}` : '-'}</td>
                    <td style={resTdStyle}>{[result.reason, ...result.observerWarnings].filter(Boolean).join(' ') || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onClose} disabled={isLoading} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
          <button onClick={() => { void preview() }} disabled={isLoading || normalizedIds.length === 0} style={previewBtnStyle}>
            {loading === 'preview' ? t('asset-pilot.integrity.previewing') : t('asset-pilot.integrity.preview')}
          </button>
          {canApply && reviewedPlan != null && (
            <button onClick={() => { void apply() }} disabled={isLoading} style={applyBtnStyle}>
              {loading === 'apply' ? t('asset-pilot.integrity.applying') : t('asset-pilot.integrity.apply-reviewed')}
            </button>
          )}
        </div>
        {!canApply && <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', textAlign: 'right', margin: '8px 0 0' }}>{t('asset-pilot.integrity.operate-required')}</p>}
      </div>
    </div>
  )
}

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle, width: 520, maxWidth: 'calc(100vw - 32px)', maxHeight: 'calc(100vh - 32px)', overflow: 'auto',
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13,
}
const previewBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-primary)', borderRadius: 6, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const applyBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: 'var(--ap-color-success)', color: 'var(--ap-color-text-light-solid)', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const resThStyle: React.CSSProperties = { textAlign: 'left', padding: '4px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const resTdStyle: React.CSSProperties = { padding: '4px 6px', borderBottom: '1px solid var(--ap-color-fill-secondary)' }
