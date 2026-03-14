import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { OperationResult, MoveOperation, OrganizeResponse, ExplainResponse } from '../../types'
import { StatusTag } from '../shared/status-tag'
import { OpenButton } from '../shared/open-button'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { ExplainModal } from './explain-modal'

export const OrganizeForm: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [objectId, setObjectId] = useState('')
  const [dryRun, setDryRun] = useState(true)
  const [async, setAsync] = useState(false)
  const [loading, setLoading] = useState(false)
  const [explaining, setExplaining] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [results, setResults] = useState<OperationResult[] | null>(null)
  const [dryRunOps, setDryRunOps] = useState<MoveOperation[] | null>(null)
  const [explainData, setExplainData] = useState<ExplainResponse | null>(null)

  const handleExplain = async (): Promise<void> => {
    const id = parseInt(objectId, 10)
    if (isNaN(id) || id <= 0) {
      setError(t('asset-pilot.operations.enter-valid-id'))
      return
    }

    setExplaining(true)
    setError(null)

    try {
      const data = await assetPilotApi.explainOrganize(id)
      setExplainData(data)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Explain failed')
    } finally {
      setExplaining(false)
    }
  }

  const handleOrganize = async (): Promise<void> => {
    const id = parseInt(objectId, 10)
    if (isNaN(id) || id <= 0) {
      setError(t('asset-pilot.operations.enter-valid-id'))
      return
    }

    setLoading(true)
    setError(null)
    setResults(null)
    setDryRunOps(null)

    try {
      const res: OrganizeResponse = await assetPilotApi.organize({ objectId: id, dryRun, async })

      if (res.dryRun === true && res.operations != null) {
        setDryRunOps(res.operations)
      } else if (res.results != null) {
        setResults(res.results)
      } else if (res.message != null) {
        toast.success(res.message)
      }
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Operation failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div>
      <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.operations.single-title')}</h4>

      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
        <input
          type="number"
          placeholder={t('asset-pilot.operations.object-id')}
          value={objectId}
          onChange={e => setObjectId(e.target.value)}
          style={inputStyle}
        />

        <label style={toggleStyle}>
          <input type="checkbox" checked={dryRun} onChange={e => setDryRun(e.target.checked)} />
          <span>{t('asset-pilot.operations.dry-run')}</span>
        </label>

        <label style={toggleStyle}>
          <input type="checkbox" checked={async} onChange={e => setAsync(e.target.checked)} disabled={dryRun} />
          <span>{t('asset-pilot.operations.async')}</span>
        </label>

        {operate && (
          <button onClick={() => { void handleOrganize() }} disabled={loading || explaining} style={primaryBtnStyle}>
            {loading ? t('asset-pilot.operations.processing') : t('asset-pilot.operations.organize')}
          </button>
        )}

        <button onClick={() => { void handleExplain() }} disabled={loading || explaining} style={explainBtnStyle}>
          {explaining ? t('asset-pilot.explain.loading') : t('asset-pilot.explain.button')}
        </button>
      </div>

      {error != null && <p style={{ color: '#ff4d4f', fontSize: 13 }}>{error}</p>}

      {dryRunOps != null && (
        <div>
          <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>
            {t('asset-pilot.operations.dry-run-result', { count: dryRunOps.length })}
          </p>
          {dryRunOps.length > 0 && (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                  <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.current-path')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.target-path')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.rule')}</th>
                </tr>
              </thead>
              <tbody>
                {dryRunOps.map((op, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #f5f5f5' }}>
                    <td style={tdStyle}><OpenButton id={op.assetId} type="asset" /></td>
                    <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 11 }}>{op.sourcePath}</td>
                    <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 11 }}>{op.targetPath}</td>
                    <td style={tdStyle}>{op.ruleName}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {explainData != null && (
        <ExplainModal data={explainData} onClose={() => setExplainData(null)} />
      )}

      {results != null && results.length > 0 && (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
              <th style={thStyle}>{t('asset-pilot.columns.status')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.message')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.rule')}</th>
            </tr>
          </thead>
          <tbody>
            {results.map((r, i) => (
              <tr key={i} style={{ borderBottom: '1px solid #f5f5f5' }}>
                <td style={tdStyle}><StatusTag status={r.status} /></td>
                <td style={tdStyle}>{r.message}</td>
                <td style={tdStyle}>{r.operation?.assetId != null ? <OpenButton id={r.operation.assetId} type="asset" /> : '-'}</td>
                <td style={tdStyle}>{r.operation?.ruleName ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

const inputStyle: React.CSSProperties = { width: 160, padding: '6px 12px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 13, outline: 'none' }
const toggleStyle: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center', fontSize: 13, cursor: 'pointer' }
const primaryBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: '#1677ff', color: '#fff',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const explainBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #b7eb8f', borderRadius: 6, background: '#f6ffed', color: '#389e0d',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px', fontSize: 11, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '6px' }
