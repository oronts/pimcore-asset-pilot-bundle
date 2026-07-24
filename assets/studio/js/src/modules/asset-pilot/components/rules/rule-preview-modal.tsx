import React, { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError, assetPilotApi } from '../../services/api'
import type { MoveOperation } from '../../types'
import { useToast } from '../../hooks/use-toast'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { usePermissions } from '../../hooks/use-permissions'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { modalOverlayStyle, modalSurfaceStyle } from '../shared/modal-styles'

interface RulePreviewModalProps {
  ruleName: string
  onClose: () => void
}

export const RulePreviewModal: React.FC<RulePreviewModalProps> = ({ ruleName, onClose }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [objectId, setObjectId] = useState('')
  const [preview, setPreview] = useState<{ objectId: number; operations: MoveOperation[]; planToken: string } | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [applying, setApplying] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const request = useRef<AbortController | null>(null)
  const modalRef = useModalDismiss<HTMLDivElement>(onClose, !loading && !applying, !confirming)
  const titleId = useId()

  useEffect(() => () => request.current?.abort(), [])

  const runPreview = async (): Promise<void> => {
    const id = parseInt(objectId, 10)
    if (isNaN(id) || id <= 0) {
      setError(t('asset-pilot.rule-preview.invalid-id'))
      return
    }
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setLoading(true)
    setError(null)
    setPreview(null)
    try {
      const result = await assetPilotApi.previewRule(ruleName, id, controller.signal)
      if (!controller.signal.aborted) {
        if (!Array.isArray(result.operations) || result.planToken == null || result.planToken === '') {
          throw new Error(t('asset-pilot.operations.preview-invalid'))
        }
        setPreview({ objectId: id, ...result })
      }
    } catch (e) {
      if (!(e instanceof Error && e.name === 'AbortError')) {
        setError(e instanceof Error ? e.message : t('asset-pilot.rule-preview.preview-failed'))
      }
    } finally {
      if (!controller.signal.aborted) setLoading(false)
    }
  }

  const applyNow = async (): Promise<void> => {
    if (preview == null) return
    setApplying(true)
    try {
      await assetPilotApi.applyRule(ruleName, preview.objectId, preview.planToken)
      toast.success(t('asset-pilot.rule-preview.apply-success'))
      onClose()
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) setPreview(null)
      toast.error(e instanceof Error ? e.message : t('asset-pilot.common.unknown-error'))
    } finally {
      setApplying(false)
      setConfirming(false)
    }
  }

  const dismiss = (): void => {
    request.current?.abort()
    onClose()
  }

  return (
    <>
      <div role="presentation" aria-hidden={confirming || undefined} style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget && !loading && !applying && !confirming) dismiss() }}>
        <div ref={modalRef} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} style={modalStyle}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
            <h3 id={titleId} style={{ margin: 0, fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.rule-preview.title', { name: ruleName })}</h3>
            <button onClick={dismiss} disabled={loading || applying} aria-label={t('asset-pilot.common.close')} style={closeBtnStyle}>&times;</button>
          </div>

          <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
            <input type="number" aria-label={t('asset-pilot.rule-preview.object-id')} placeholder={t('asset-pilot.rule-preview.object-id')} value={objectId} onChange={e => { request.current?.abort(); setObjectId(e.target.value); setPreview(null); setError(null); setLoading(false) }} style={inputStyle} />
            <button onClick={() => { void runPreview() }} disabled={loading} style={primaryBtnStyle}>
              {loading ? t('asset-pilot.common.loading') : t('asset-pilot.rule-preview.run')}
            </button>
          </div>

          {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13, margin: '0 0 12px' }}>{error}</p>}

          {preview != null && (
            <div>
              {preview.operations.length === 0 ? (
                <p style={{ color: 'var(--ap-color-text-secondary)', fontSize: 13 }}>{t('asset-pilot.rule-preview.no-operations')}</p>
              ) : (
                <>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)', marginBottom: 16 }}>
                    <thead>
                      <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                        <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                        <th style={thStyle}>{t('asset-pilot.columns.current-path')}</th>
                        <th style={thStyle}>{t('asset-pilot.columns.would-move-to')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.operations.map((op, i) => (
                        <tr key={i} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                          <td style={tdStyle}>{op.assetId}</td>
                          <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{op.sourcePath}</td>
                          <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{op.targetPath}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {operate && (
                    <button onClick={() => setConfirming(true)} disabled={applying || loading} style={applyBtnStyle}>
                      {applying ? t('asset-pilot.common.loading') : t('asset-pilot.rule-preview.apply-now')}
                    </button>
                  )}
                </>
              )}
            </div>
          )}

        </div>
      </div>
      {confirming && preview != null && (
        <ConfirmDialog
          title={t('asset-pilot.rule-preview.confirm-title')}
          description={t('asset-pilot.rule-preview.confirm-description', { id: preview.objectId, count: preview.operations.length })}
          confirmLabel={t('asset-pilot.rule-preview.apply-now')}
          variant="warning"
          loading={applying}
          onConfirm={() => { void applyNow() }}
          onCancel={() => setConfirming(false)}
        />
      )}
    </>
  )
}

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle, width: 640, maxWidth: 'calc(100vw - 32px)', maxHeight: '80vh', overflow: 'auto',
}
const closeBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', fontSize: 22, cursor: 'pointer', color: 'var(--ap-color-text-secondary)',
}
const inputStyle: React.CSSProperties = {
  flex: 1, padding: '6px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 6, fontSize: 13, outline: 'none',
}
const primaryBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: 'var(--ap-color-primary)', color: 'var(--ap-color-text-light-solid)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const applyBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-warning-border)', borderRadius: 6, background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '6px' }
