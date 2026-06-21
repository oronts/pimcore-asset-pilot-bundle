import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { DuplicateGroup, MergeResult, MergeStrategies } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { truncate } from '../../utils/format'

interface MergeModalProps {
  group: DuplicateGroup
  strategies: MergeStrategies | null
  canApply: boolean
  onClose: () => void
  onMerged: () => void
}

export const MergeModal: React.FC<MergeModalProps> = ({ group, strategies, canApply, onClose, onMerged }) => {
  const { t } = useTranslation()
  const modalRef = useModalDismiss<HTMLDivElement>(onClose)
  const [canonicalId, setCanonicalId] = useState<number>(Math.min(...group.assetIds))
  const [strategy, setStrategy] = useState<string>('')
  const [result, setResult] = useState<MergeResult | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const run = async (dryRun: boolean): Promise<void> => {
    setLoading(true)
    setError(null)
    try {
      const res = await assetPilotApi.mergeDuplicates(group.checksum, canonicalId, strategy || undefined, dryRun)
      setResult(res)
      if (!dryRun) onMerged()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Merge failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={overlayStyle} onClick={onClose}>
      <div ref={modalRef} role="dialog" aria-modal="true" tabIndex={-1} style={modalStyle} onClick={e => e.stopPropagation()}>
        <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.duplicates.merge-title')}</h3>
        <p style={{ fontSize: 12, color: '#8c8c8c', margin: '0 0 16px', fontFamily: 'monospace' }}>{truncate(group.checksum, 24)}</p>

        <label style={labelStyle}>{t('asset-pilot.duplicates.canonical')}</label>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
          {group.assetIds.map(id => (
            <label key={id} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, cursor: 'pointer' }}>
              <input type="radio" name="canonical" checked={canonicalId === id} onChange={() => setCanonicalId(id)} />
              #{id}
            </label>
          ))}
        </div>

        <label style={labelStyle} htmlFor="merge-strategy">{t('asset-pilot.duplicates.strategy')}</label>
        <select
          id="merge-strategy"
          value={strategy}
          onChange={e => setStrategy(e.target.value)}
          style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #d9d9d9', fontSize: 13, marginBottom: 16 }}
        >
          <option value="">
            {strategies != null
              ? t('asset-pilot.duplicates.strategy-default', { name: strategies.default })
              : t('asset-pilot.common.loading')}
          </option>
          {(strategies?.strategies ?? []).map(name => (
            <option key={name} value={name}>{name}</option>
          ))}
        </select>

        <p style={{ fontSize: 12, color: '#fa8c16', marginBottom: 12 }}>{t('asset-pilot.duplicates.warning')}</p>

        {error != null && <p style={{ color: '#ff4d4f', fontSize: 13, marginBottom: 12 }}>{error}</p>}

        {result != null && (
          <div style={{ background: '#fafafa', borderRadius: 6, padding: 12, marginBottom: 16, maxHeight: 200, overflow: 'auto' }}>
            <p style={{ margin: '0 0 8px', fontSize: 12, fontWeight: 600 }}>
              {result.dryRun ? t('asset-pilot.duplicates.preview-result') : t('asset-pilot.duplicates.result')}
              {' '}({t('asset-pilot.duplicates.kept', { id: result.canonicalId })})
            </p>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ borderBottom: '1px solid #e8e8e8' }}>
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
                    <td style={{ ...resTdStyle, color: '#8c8c8c' }}>{d.reason}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onClose} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
          <button onClick={() => { void run(true) }} disabled={loading} style={previewBtnStyle}>
            {loading ? t('asset-pilot.common.loading') : t('asset-pilot.duplicates.preview')}
          </button>
          {canApply && (
            <button onClick={() => { void run(false) }} disabled={loading} style={applyBtnStyle}>
              {loading ? t('asset-pilot.duplicates.applying') : t('asset-pilot.duplicates.apply')}
            </button>
          )}
        </div>
        {!canApply && <p style={{ fontSize: 11, color: '#8c8c8c', textAlign: 'right', margin: '8px 0 0' }}>{t('asset-pilot.duplicates.admin-required')}</p>}
      </div>
    </div>
  )
}

const overlayStyle: React.CSSProperties = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', display: 'flex',
  alignItems: 'center', justifyContent: 'center', zIndex: 1000,
}
const modalStyle: React.CSSProperties = {
  background: '#fff', borderRadius: 12, padding: 24, width: 520,
  boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
}
const labelStyle: React.CSSProperties = { display: 'block', fontSize: 12, fontWeight: 500, color: '#595959', marginBottom: 6 }
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13,
}
const previewBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #1677ff', borderRadius: 6, background: '#fff', color: '#1677ff', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const applyBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: '#fa8c16', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const resThStyle: React.CSSProperties = { textAlign: 'left', padding: '4px 6px', fontSize: 11, color: '#8c8c8c', fontWeight: 500 }
const resTdStyle: React.CSSProperties = { padding: '4px 6px', borderBottom: '1px solid #f5f5f5' }
