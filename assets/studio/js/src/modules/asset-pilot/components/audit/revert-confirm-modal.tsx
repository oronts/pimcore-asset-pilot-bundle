import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { AuditEntry } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { modalOverlayStyle, modalSurfaceStyle } from '../shared/modal-styles'

interface RevertConfirmModalProps {
  entry: AuditEntry
  onClose: () => void
  onReverted: () => void
}

export const RevertConfirmModal: React.FC<RevertConfirmModalProps> = ({ entry, onClose, onReverted }) => {
  const { t } = useTranslation()
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const modalRef = useModalDismiss<HTMLDivElement>(onClose, !loading)

  const handleRevert = async (): Promise<void> => {
    setLoading(true)
    setError(null)
    try {
      await assetPilotApi.revertOperation(entry.id)
      onReverted()
    } catch (e) {
      setError(e instanceof Error ? e.message : t('asset-pilot.common.unknown-error'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div role="presentation" style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget && !loading) onClose() }}>
      <div ref={modalRef} role="dialog" aria-modal="true" aria-label={t('asset-pilot.revert.title')} tabIndex={-1} style={modalStyle}>
        <h3 style={{ margin: '0 0 16px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.revert.title')}</h3>

        <p style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', marginBottom: 12 }}>
          {t('asset-pilot.revert.description')}
        </p>

        <div style={{ background: 'var(--ap-color-fill-alter)', borderRadius: 6, padding: 12, marginBottom: 16, fontSize: 'var(--ap-font-size)' }}>
          <Row label={t('asset-pilot.columns.asset-id')} value={String(entry.asset_id)} />
          <Row label={t('asset-pilot.revert.current-path')} value={entry.asset_path_to} mono />
          <Row label={t('asset-pilot.revert.revert-to')} value={entry.asset_path_from} mono />
          <Row label={t('asset-pilot.columns.rule')} value={entry.rule_name} />
        </div>

        <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-warning-text-active)', marginBottom: 16 }}>
          {t('asset-pilot.revert.warning')}
        </p>

        {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13, marginBottom: 12 }}>{error}</p>}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onClose} disabled={loading} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
          <button onClick={() => { void handleRevert() }} disabled={loading} style={revertBtnStyle}>
            {loading ? t('asset-pilot.revert.reverting') : t('asset-pilot.revert.confirm')}
          </button>
        </div>
      </div>
    </div>
  )
}

const Row: React.FC<{ label: string; value: string; mono?: boolean }> = ({ label, value, mono }) => (
  <div style={{ display: 'flex', gap: 8, marginBottom: 4 }}>
    <span style={{ minWidth: 90, color: 'var(--ap-color-text-secondary)', fontWeight: 500 }}>{label}</span>
    <span style={mono ? { fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' } : undefined}>{value}</span>
  </div>
)

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle, width: 480, maxWidth: 'calc(100vw - 32px)', maxHeight: 'calc(100vh - 32px)', overflow: 'auto',
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 13,
}
const revertBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: 'var(--ap-color-warning)', color: 'var(--ap-color-text-light-solid)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
