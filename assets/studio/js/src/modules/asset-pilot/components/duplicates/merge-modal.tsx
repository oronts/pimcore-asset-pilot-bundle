import React, { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError, assetPilotApi } from '../../services/api'
import type { DuplicateGroup, MergeResult, MergeStrategies } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { truncate } from '../../utils/format'
import { OperationRunPanel } from '../operations/operation-run-panel'
import { modalOverlayStyle, modalSurfaceStyle } from '../shared/modal-styles'

interface MergeModalProps {
  group: DuplicateGroup
  strategies: MergeStrategies | null
  strategiesLoading: boolean
  strategiesError: string | null
  canApply: boolean
  onClose: () => void
  onMerged: () => void
}

export const MergeModal: React.FC<MergeModalProps> = ({ group, strategies, strategiesLoading, strategiesError, canApply, onClose, onMerged }) => {
  const { t } = useTranslation()
  const [canonicalId, setCanonicalId] = useState<number>(Math.min(...group.assetIds))
  const [strategy, setStrategy] = useState<string>('')
  const [result, setResult] = useState<MergeResult | null>(null)
  const [planToken, setPlanToken] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const modalRef = useModalDismiss<HTMLDivElement>(onClose, !loading)
  const [previewParams, setPreviewParams] = useState<{ canonicalId: number; strategy: string } | null>(null)
  const request = useRef<AbortController | null>(null)
  const titleId = useId()

  useEffect(() => () => request.current?.abort(), [])

  const run = async (dryRun: boolean): Promise<void> => {
    const params = dryRun ? { canonicalId, strategy } : previewParams
    if (params == null) return
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setLoading(true)
    setError(null)
    try {
      const res = await assetPilotApi.mergeDuplicates(
        group.checksum,
        params.canonicalId,
        params.strategy || undefined,
        dryRun,
        dryRun ? undefined : planToken ?? undefined,
        controller.signal,
      )
      if (!controller.signal.aborted) {
        if (dryRun && (res.dryRun !== true || res.planToken == null || res.planToken === '')) {
          throw new Error(t('asset-pilot.operations.preview-invalid'))
        }
        setResult(res)
        if (dryRun) {
          setPreviewParams(params)
          setPlanToken(res.planToken)
        }
        else if (res.status === 'completed') onMerged()
      }
    } catch (e) {
      if (!(e instanceof Error && e.name === 'AbortError')) {
        if (e instanceof ApiError && e.status === 409) {
          setResult(null)
          setPreviewParams(null)
          setPlanToken(null)
        }
        setError(e instanceof Error ? e.message : t('asset-pilot.duplicates.merge-failed'))
      }
    } finally {
      if (!controller.signal.aborted) setLoading(false)
    }
  }

  const resume = async (): Promise<void> => {
    if (result?.runId == null) return
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setLoading(true)
    setError(null)
    try {
      const resumed = await assetPilotApi.resumeDuplicateMerge(result.runId, controller.signal)
      if (!controller.signal.aborted) {
        setResult(resumed)
        if (resumed.status === 'completed') onMerged()
      }
    } catch (e) {
      if (!(e instanceof Error && e.name === 'AbortError')) {
        setError(e instanceof Error ? e.message : t('asset-pilot.duplicates.merge-failed'))
      }
    } finally {
      if (!controller.signal.aborted) setLoading(false)
    }
  }

  const invalidatePreview = (): void => {
    request.current?.abort()
    setResult(null)
    setPreviewParams(null)
    setPlanToken(null)
    setLoading(false)
    setError(null)
  }

  return (
    <div role="presentation" style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget && !loading) onClose() }}>
      <div ref={modalRef} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} style={modalStyle}>
        <h3 id={titleId} style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.duplicates.merge-title')}</h3>
        <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', margin: '0 0 16px', fontFamily: 'monospace' }}>{truncate(group.checksum, 24)}</p>

        <label style={labelStyle}>{t('asset-pilot.duplicates.canonical')}</label>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
          {group.assetIds.map(id => (
            <label key={id} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 'var(--ap-font-size)', cursor: 'pointer' }}>
              <input type="radio" name="canonical" checked={canonicalId === id} disabled={loading} onChange={() => { invalidatePreview(); setCanonicalId(id) }} />
              #{id}
            </label>
          ))}
        </div>

        <label style={labelStyle} htmlFor="merge-strategy">{t('asset-pilot.duplicates.strategy')}</label>
        <select
          id="merge-strategy"
          value={strategy}
          onChange={e => { invalidatePreview(); setStrategy(e.target.value) }}
          disabled={loading || strategiesLoading || strategiesError != null}
          style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid var(--ap-color-border)', fontSize: 13, marginBottom: 16 }}
        >
          <option value="">
            {strategies != null
              ? t('asset-pilot.duplicates.strategy-default', { name: strategies.default })
              : strategiesLoading ? t('asset-pilot.common.loading') : t('asset-pilot.common.unavailable')}
          </option>
          {(strategies?.strategies ?? []).map(name => (
            <option key={name} value={name}>{name}</option>
          ))}
        </select>

        {strategiesError != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }}>{t('asset-pilot.common.error', { message: strategiesError })}</p>}

        <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-warning-text-active)', marginBottom: 12 }}>{t('asset-pilot.duplicates.warning')}</p>

        {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13, marginBottom: 12 }}>{error}</p>}

        {result != null && (
          <div style={{ background: 'var(--ap-color-fill-alter)', borderRadius: 6, padding: 12, marginBottom: 16, maxHeight: 200, overflow: 'auto' }}>
            <p style={{ margin: '0 0 8px', fontSize: 'var(--ap-font-size)', fontWeight: 600 }}>
              {result.dryRun ? t('asset-pilot.duplicates.preview-result') : t('asset-pilot.duplicates.result')}
              {' '}({t('asset-pilot.duplicates.kept', { id: result.canonicalId })})
            </p>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--ap-color-border-secondary)' }}>
                  <th style={resThStyle}>{t('asset-pilot.duplicates.copy')}</th>
                  <th style={resThStyle}>{t('asset-pilot.columns.outcome')}</th>
                  <th style={resThStyle}>{t('asset-pilot.columns.reason')}</th>
                </tr>
              </thead>
              <tbody>
                {result.dispositions.map(d => (
                  <tr key={d.copyId}>
                    <td style={resTdStyle}>#{d.copyId}</td>
                    <td style={resTdStyle}>{t(`asset-pilot.duplicates.outcome.${d.outcome}`, { defaultValue: d.outcome })}</td>
                    <td style={{ ...resTdStyle, color: 'var(--ap-color-text-secondary)' }}>{d.reason}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {result?.runId != null && result.dryRun === false && result.status !== 'completed' && (
          <>
            <OperationRunPanel runId={result.runId} />
            {(result.status === 'queued' || result.status === 'running') && (
              <button type="button" onClick={() => { void resume() }} disabled={loading} style={previewBtnStyle}>
                {loading ? t('asset-pilot.common.loading') : t('asset-pilot.duplicates.resume')}
              </button>
            )}
          </>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onClose} disabled={loading} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
          <button onClick={() => { void run(true) }} disabled={loading || strategies == null || strategiesError != null} style={previewBtnStyle}>
            {loading ? t('asset-pilot.common.loading') : t('asset-pilot.duplicates.preview')}
          </button>
          {canApply && (
            <button onClick={() => { void run(false) }} disabled={loading || previewParams == null || planToken == null || result?.dryRun !== true} style={applyBtnStyle}>
              {loading ? t('asset-pilot.duplicates.applying') : t('asset-pilot.duplicates.apply')}
            </button>
          )}
        </div>
        {!canApply && <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', textAlign: 'right', margin: '8px 0 0' }}>{t('asset-pilot.duplicates.admin-required')}</p>}
      </div>
    </div>
  )
}

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle, width: 520, maxWidth: 'calc(100vw - 32px)', maxHeight: 'calc(100vh - 32px)', overflow: 'auto',
}
const labelStyle: React.CSSProperties = { display: 'block', fontSize: 'var(--ap-font-size)', fontWeight: 500, color: 'var(--ap-color-text-secondary)', marginBottom: 6 }
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13,
}
const previewBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-primary)', borderRadius: 6, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const applyBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: 'var(--ap-color-warning)', color: 'var(--ap-color-text-light-solid)', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const resThStyle: React.CSSProperties = { textAlign: 'left', padding: '4px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const resTdStyle: React.CSSProperties = { padding: '4px 6px', borderBottom: '1px solid var(--ap-color-fill-secondary)' }
