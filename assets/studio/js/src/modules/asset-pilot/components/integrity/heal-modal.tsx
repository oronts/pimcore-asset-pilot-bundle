import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { HealResponse } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'

interface HealModalProps {
  ids: number[]
  canApply: boolean
  onClose: () => void
  onHealed: () => void
}

export const HealModal: React.FC<HealModalProps> = ({ ids, canApply, onClose, onHealed }) => {
  const { t } = useTranslation()
  const modalRef = useModalDismiss<HTMLDivElement>(onClose)
  const [result, setResult] = useState<HealResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const run = async (dryRun: boolean): Promise<void> => {
    setLoading(true)
    setError(null)
    try {
      const res = await assetPilotApi.healAssets(ids, dryRun)
      setResult(res)
      if (!dryRun) onHealed()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Heal failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={overlayStyle} onClick={onClose}>
      <div ref={modalRef} role="dialog" aria-modal="true" tabIndex={-1} style={modalStyle} onClick={e => e.stopPropagation()}>
        <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.integrity.heal-title')}</h3>
        <p style={{ fontSize: 13, color: '#595959', margin: '0 0 16px' }}>{t('asset-pilot.integrity.heal-desc', { count: ids.length })}</p>

        {error != null && <p style={{ color: '#ff4d4f', fontSize: 13, marginBottom: 12 }}>{error}</p>}

        {result != null && (
          <div style={{ background: '#fafafa', borderRadius: 6, padding: 12, marginBottom: 16, maxHeight: 220, overflow: 'auto' }}>
            <p style={{ margin: '0 0 8px', fontSize: 12, fontWeight: 600 }}>
              {result.dryRun ? t('asset-pilot.integrity.preview-result') : t('asset-pilot.integrity.result')}
            </p>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ borderBottom: '1px solid #e8e8e8' }}>
                  <th style={resThStyle}>{t('asset-pilot.columns.asset-id')}</th>
                  <th style={resThStyle}>{t('asset-pilot.columns.outcome')}</th>
                  <th style={resThStyle}>{t('asset-pilot.integrity.to-version')}</th>
                </tr>
              </thead>
              <tbody>
                {result.results.map(r => (
                  <tr key={r.assetId}>
                    <td style={resTdStyle}>#{r.assetId}</td>
                    <td style={resTdStyle}>{t(`asset-pilot.integrity.outcome.${r.outcome}`, { defaultValue: r.outcome })}</td>
                    <td style={resTdStyle}>{r.toVersion != null ? `v${r.toVersion}` : '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onClose} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
          <button onClick={() => { void run(true) }} disabled={loading} style={previewBtnStyle}>
            {loading ? t('asset-pilot.common.loading') : t('asset-pilot.integrity.preview')}
          </button>
          {canApply && (
            <button onClick={() => { void run(false) }} disabled={loading} style={applyBtnStyle}>
              {loading ? t('asset-pilot.integrity.healing') : t('asset-pilot.integrity.heal')}
            </button>
          )}
        </div>
        {!canApply && <p style={{ fontSize: 11, color: '#8c8c8c', textAlign: 'right', margin: '8px 0 0' }}>{t('asset-pilot.integrity.operate-required')}</p>}
      </div>
    </div>
  )
}

const overlayStyle: React.CSSProperties = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', display: 'flex',
  alignItems: 'center', justifyContent: 'center', zIndex: 1000,
}
const modalStyle: React.CSSProperties = {
  background: '#fff', borderRadius: 12, padding: 24, width: 520, boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13,
}
const previewBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #1677ff', borderRadius: 6, background: '#fff', color: '#1677ff', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const applyBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: '#52c41a', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const resThStyle: React.CSSProperties = { textAlign: 'left', padding: '4px 6px', fontSize: 11, color: '#8c8c8c', fontWeight: 500 }
const resTdStyle: React.CSSProperties = { padding: '4px 6px', borderBottom: '1px solid #f5f5f5' }
