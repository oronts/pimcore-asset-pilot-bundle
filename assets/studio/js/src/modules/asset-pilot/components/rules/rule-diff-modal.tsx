import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { RuleSetDiff } from '../../types'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { modalOverlayStyle, modalSurfaceStyle } from '../shared/modal-styles'

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
    <div role="presentation" style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget) onClose() }}>
      <div ref={modalRef} role="dialog" aria-modal="true" aria-label={t('asset-pilot.rules.diff.title')} tabIndex={-1} style={modalStyle}>
        <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.rules.diff.title')}</h3>
        <p style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', margin: '0 0 16px' }}>{t('asset-pilot.rules.diff.desc')}</p>

        <label style={fileLabelStyle}>
          {t('asset-pilot.rules.diff.choose-file')}
          <input type="file" accept="application/json,.json" onChange={e => { void onFile(e) }} style={{ display: 'none' }} />
        </label>
        {fileName != null && <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginLeft: 8 }}>{fileName}</span>}

        {loading && <p style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', marginTop: 16 }}>{t('asset-pilot.common.loading')}</p>}
        {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13, marginTop: 16 }}>{error}</p>}

        {diff != null && (
          <div style={{ marginTop: 16 }}>
            {!diff.hasChanges && <p style={{ fontSize: 13, color: 'var(--ap-color-success-text)' }}>{t('asset-pilot.rules.diff.identical')}</p>}
            <DiffGroup color="var(--ap-color-success-text)" label={t('asset-pilot.rules.diff.added')} names={Object.keys(diff.added)} />
            <DiffGroup color="var(--ap-color-error-text)" label={t('asset-pilot.rules.diff.removed')} names={Object.keys(diff.removed)} />
            <ChangedRules label={t('asset-pilot.rules.diff.changed')} changes={diff.changed} />
            <DiffGroup color="var(--ap-color-text-secondary)" label={t('asset-pilot.rules.diff.unchanged')} names={diff.unchanged} />
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
          <button onClick={onClose} style={cancelBtnStyle}>{t('asset-pilot.common.cancel')}</button>
        </div>
      </div>
    </div>
  )
}

const ChangedRules: React.FC<{
  label: string
  changes: RuleSetDiff['changed']
}> = ({ label, changes }) => {
  const { t } = useTranslation()
  const entries = Object.entries(changes)
  if (entries.length === 0) return null

  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ fontSize: 'var(--ap-font-size)', fontWeight: 600, color: 'var(--ap-color-warning-text)', marginBottom: 4 }}>{label} ({entries.length})</div>
      {entries.map(([name, change]) => (
        <details key={name} style={{ marginBottom: 6 }}>
          <summary style={{ cursor: 'pointer', fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{name}</summary>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 8, marginTop: 4 }}>
            <div><strong style={diffLabelStyle}>{t('asset-pilot.rules.diff.current')}</strong><pre style={diffCodeStyle}>{JSON.stringify(change.current, null, 2)}</pre></div>
            <div><strong style={diffLabelStyle}>{t('asset-pilot.rules.diff.imported')}</strong><pre style={diffCodeStyle}>{JSON.stringify(change.imported, null, 2)}</pre></div>
          </div>
        </details>
      ))}
    </div>
  )
}

const DiffGroup: React.FC<{ color: string; label: string; names: string[] }> = ({ color, label, names }) => {
  if (names.length === 0) return null

  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ fontSize: 'var(--ap-font-size)', fontWeight: 600, color, marginBottom: 4 }}>{label} ({names.length})</div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
        {names.map(name => (
          <span key={name} style={{ fontSize: 'var(--ap-font-size)', background: 'var(--ap-color-fill-secondary)', borderRadius: 4, padding: '2px 8px', fontFamily: 'monospace' }}>{name}</span>
        ))}
      </div>
    </div>
  )
}

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle, width: 520, maxWidth: 'calc(100vw - 32px)', maxHeight: '80vh', overflow: 'auto',
}
const fileLabelStyle: React.CSSProperties = {
  display: 'inline-block', padding: '6px 16px', border: '1px solid var(--ap-color-primary)', borderRadius: 6,
  background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13,
}
const diffCodeStyle: React.CSSProperties = {
  margin: 0, padding: 8, overflow: 'auto', maxHeight: 220, background: 'var(--ap-color-fill-alter)', border: '1px solid var(--ap-color-border-secondary)', borderRadius: 4, fontSize: 'var(--ap-font-size)',
}
const diffLabelStyle: React.CSSProperties = { display: 'block', marginBottom: 2, color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)' }
