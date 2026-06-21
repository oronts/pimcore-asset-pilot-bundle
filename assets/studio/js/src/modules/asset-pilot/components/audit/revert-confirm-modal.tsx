import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { AuditEntry } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'

interface RevertConfirmModalProps {
  entry: AuditEntry
  onClose: () => void
  onReverted: () => void
}

export const RevertConfirmModal: React.FC<RevertConfirmModalProps> = ({ entry, onClose, onReverted }) => {
  const { t } = useTranslation()
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const modalRef = useModalDismiss<HTMLDivElement>(onClose)

  const handleRevert = async (): Promise<void> => {
    setLoading(true)
    setError(null)
    try {
      await assetPilotApi.revertOperation(entry.id)
      onReverted()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Revert failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={overlayStyle} onClick={onClose}>
      <div ref={modalRef} role="dialog" aria-modal="true" tabIndex={-1} style={modalStyle} onClick={e => e.stopPropagation()}>
        <h3 style={{ margin: '0 0 16px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.revert.title')}</h3>

        <p style={{ fontSize: 13, color: '#595959', marginBottom: 12 }}>
          {t('asset-pilot.revert.description')}
        </p>

        <div style={{ background: '#fafafa', borderRadius: 6, padding: 12, marginBottom: 16, fontSize: 12 }}>
          <Row label={t('asset-pilot.columns.asset-id')} value={String(entry.asset_id)} />
          <Row label={t('asset-pilot.revert.current-path')} value={entry.asset_path_to} mono />
          <Row label={t('asset-pilot.revert.revert-to')} value={entry.asset_path_from} mono />
          <Row label={t('asset-pilot.columns.rule')} value={entry.rule_name} />
        </div>

        <p style={{ fontSize: 12, color: '#fa8c16', marginBottom: 16 }}>
          {t('asset-pilot.revert.warning')}
        </p>

        {error != null && <p style={{ color: '#ff4d4f', fontSize: 13, marginBottom: 12 }}>{error}</p>}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onClose} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
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
    <span style={{ minWidth: 90, color: '#8c8c8c', fontWeight: 500 }}>{label}</span>
    <span style={mono ? { fontFamily: 'monospace', fontSize: 11 } : undefined}>{value}</span>
  </div>
)

const overlayStyle: React.CSSProperties = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', display: 'flex',
  alignItems: 'center', justifyContent: 'center', zIndex: 1000,
}
const modalStyle: React.CSSProperties = {
  background: '#fff', borderRadius: 12, padding: 24, width: 480,
  boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff',
  cursor: 'pointer', fontSize: 13,
}
const revertBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: '#fa8c16', color: '#fff',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
