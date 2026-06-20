import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { MoveOperation } from '../../types'
import { useToast } from '../../hooks/use-toast'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { usePermissions } from '../../hooks/use-permissions'

interface RulePreviewModalProps {
  ruleName: string
  onClose: () => void
}

export const RulePreviewModal: React.FC<RulePreviewModalProps> = ({ ruleName, onClose }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [objectId, setObjectId] = useState('')
  const [results, setResults] = useState<MoveOperation[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [applying, setApplying] = useState(false)
  const modalRef = useModalDismiss<HTMLDivElement>(onClose)

  const runPreview = async (): Promise<void> => {
    const id = parseInt(objectId, 10)
    if (isNaN(id) || id <= 0) {
      setError(t('asset-pilot.rule-preview.invalid-id'))
      return
    }
    setLoading(true)
    setError(null)
    try {
      const data = await assetPilotApi.previewRule(ruleName, id) as MoveOperation[]
      setResults(data)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Preview failed')
    } finally {
      setLoading(false)
    }
  }

  const applyNow = async (): Promise<void> => {
    const id = parseInt(objectId, 10)
    if (isNaN(id)) return
    setApplying(true)
    try {
      await assetPilotApi.applyRule(ruleName, id)
      toast.success(t('asset-pilot.rule-preview.apply-success'))
      onClose()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Failed')
    } finally {
      setApplying(false)
    }
  }

  return (
    <div style={overlayStyle} onClick={onClose}>
      <div ref={modalRef} role="dialog" aria-modal="true" tabIndex={-1} style={modalStyle} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.rule-preview.title', { name: ruleName })}</h3>
          <button onClick={onClose} style={closeBtnStyle}>&times;</button>
        </div>

        <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
          <input type="number" placeholder={t('asset-pilot.rule-preview.object-id')} value={objectId} onChange={e => setObjectId(e.target.value)} style={inputStyle} />
          <button onClick={() => { void runPreview() }} disabled={loading} style={primaryBtnStyle}>
            {loading ? t('asset-pilot.common.loading') : t('asset-pilot.rule-preview.run')}
          </button>
        </div>

        {error != null && <p style={{ color: '#ff4d4f', fontSize: 13, margin: '0 0 12px' }}>{error}</p>}

        {results != null && (
          <div>
            {results.length === 0 ? (
              <p style={{ color: '#8c8c8c', fontSize: 13 }}>{t('asset-pilot.rule-preview.no-operations')}</p>
            ) : (
              <>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 16 }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                      <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                      <th style={thStyle}>{t('asset-pilot.columns.current-path')}</th>
                      <th style={thStyle}>{t('asset-pilot.columns.would-move-to')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {results.map((op, i) => (
                      <tr key={i} style={{ borderBottom: '1px solid #f5f5f5' }}>
                        <td style={tdStyle}>{op.assetId}</td>
                        <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 11 }}>{op.sourcePath}</td>
                        <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 11 }}>{op.targetPath}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {operate && (
                  <button onClick={() => { void applyNow() }} disabled={applying} style={applyBtnStyle}>
                    {applying ? t('asset-pilot.common.loading') : t('asset-pilot.rule-preview.apply-now')}
                  </button>
                )}
              </>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

const overlayStyle: React.CSSProperties = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', display: 'flex',
  alignItems: 'center', justifyContent: 'center', zIndex: 1000,
}
const modalStyle: React.CSSProperties = {
  background: '#fff', borderRadius: 12, padding: 24, width: 640, maxHeight: '80vh',
  overflow: 'auto', boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
}
const closeBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', fontSize: 22, cursor: 'pointer', color: '#8c8c8c',
}
const inputStyle: React.CSSProperties = {
  flex: 1, padding: '6px 12px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 13, outline: 'none',
}
const primaryBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: '#1677ff', color: '#fff',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const applyBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #fa8c16', borderRadius: 6, background: '#fff7e6', color: '#fa8c16',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px', fontSize: 11, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '6px' }
