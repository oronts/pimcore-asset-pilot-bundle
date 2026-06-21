import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { RuleSetDiff } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'

interface RuleDiffModalProps {
  onClose: () => void
}

export const RuleDiffModal: React.FC<RuleDiffModalProps> = ({ onClose }) => {
  const { t } = useTranslation()
  const modalRef = useModalDismiss<HTMLDivElement>(onClose)
  const [fileName, setFileName] = useState<string | null>(null)
  const [diff, setDiff] = useState<RuleSetDiff | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const onFile = async (event: React.ChangeEvent<HTMLInputElement>): Promise<void> => {
    const file = event.target.files?.[0]
    if (file == null) return
    setFileName(file.name)
    setDiff(null)
    setError(null)
    setLoading(true)
    try {
      const artifact: unknown = JSON.parse(await file.text())
      setDiff(await assetPilotApi.diffRules(artifact))
    } catch (e) {
      setError(e instanceof Error ? e.message : t('asset-pilot.rules.diff.failed'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={overlayStyle} onClick={onClose}>
      <div ref={modalRef} role="dialog" aria-modal="true" tabIndex={-1} style={modalStyle} onClick={e => e.stopPropagation()}>
        <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.rules.diff.title')}</h3>
        <p style={{ fontSize: 13, color: '#595959', margin: '0 0 16px' }}>{t('asset-pilot.rules.diff.desc')}</p>

        <label style={fileLabelStyle}>
          {t('asset-pilot.rules.diff.choose-file')}
          <input type="file" accept="application/json,.json" onChange={e => { void onFile(e) }} style={{ display: 'none' }} />
        </label>
        {fileName != null && <span style={{ fontSize: 12, color: '#8c8c8c', marginLeft: 8 }}>{fileName}</span>}

        {loading && <p style={{ fontSize: 13, color: '#8c8c8c', marginTop: 16 }}>{t('asset-pilot.common.loading')}</p>}
        {error != null && <p style={{ color: '#ff4d4f', fontSize: 13, marginTop: 16 }}>{error}</p>}

        {diff != null && (
          <div style={{ marginTop: 16 }}>
            {!diff.hasChanges && <p style={{ fontSize: 13, color: '#52c41a' }}>{t('asset-pilot.rules.diff.identical')}</p>}
            <DiffGroup color="#52c41a" label={t('asset-pilot.rules.diff.added')} names={Object.keys(diff.added)} />
            <DiffGroup color="#ff4d4f" label={t('asset-pilot.rules.diff.removed')} names={Object.keys(diff.removed)} />
            <DiffGroup color="#fa8c16" label={t('asset-pilot.rules.diff.changed')} names={Object.keys(diff.changed)} />
            <DiffGroup color="#8c8c8c" label={t('asset-pilot.rules.diff.unchanged')} names={diff.unchanged} />
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
          <button onClick={onClose} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
        </div>
      </div>
    </div>
  )
}

const DiffGroup: React.FC<{ color: string; label: string; names: string[] }> = ({ color, label, names }) => {
  if (names.length === 0) return null

  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ fontSize: 12, fontWeight: 600, color, marginBottom: 4 }}>{label} ({names.length})</div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
        {names.map(name => (
          <span key={name} style={{ fontSize: 12, background: '#f5f5f5', borderRadius: 4, padding: '2px 8px', fontFamily: 'monospace' }}>{name}</span>
        ))}
      </div>
    </div>
  )
}

const overlayStyle: React.CSSProperties = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', display: 'flex',
  alignItems: 'center', justifyContent: 'center', zIndex: 1000,
}
const modalStyle: React.CSSProperties = {
  background: '#fff', borderRadius: 12, padding: 24, width: 520, maxHeight: '80vh', overflow: 'auto',
  boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
}
const fileLabelStyle: React.CSSProperties = {
  display: 'inline-block', padding: '6px 16px', border: '1px solid #1677ff', borderRadius: 6,
  background: '#fff', color: '#1677ff', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13,
}
