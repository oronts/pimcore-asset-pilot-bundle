import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { OperationResult, MoveOperation, ExplainResponse } from '../../types'
import { StatusTag } from '../shared/status-tag'
import { OpenButton } from '../shared/open-button'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { useReviewedOperation, requireOperations } from '../../hooks/use-reviewed-operation'
import { ExplainModal } from './explain-modal'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { OperationRunPanel } from './operation-run-panel'

interface ReviewedOrganization {
  objectId: number
  async: boolean
  planToken: string | null
  operations: MoveOperation[]
}

export const OrganizeForm: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [objectId, setObjectId] = useState('')
  const [async, setAsync] = useState(false)
  const [explaining, setExplaining] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [results, setResults] = useState<OperationResult[] | null>(null)
  const [explainData, setExplainData] = useState<ExplainResponse | null>(null)
  const op = useReviewedOperation<ReviewedOrganization>({ messageNamespace: 'asset-pilot.operations', validatePreview: requireOperations })

  const resetReview = (): void => {
    setResults(null)
    setExplainData(null)
    op.clear()
  }

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
      toast.error(e instanceof Error ? e.message : t('asset-pilot.common.unknown-error'))
    } finally {
      setExplaining(false)
    }
  }

  const handlePreview = async (): Promise<void> => {
    const id = parseInt(objectId, 10)
    if (isNaN(id) || id <= 0) {
      setError(t('asset-pilot.operations.enter-valid-id'))
      return
    }
    setError(null)
    setResults(null)
    await op.review(
      signal => assetPilotApi.organize({ objectId: id, dryRun: true, async }, signal),
      res => ({ objectId: id, async, planToken: res.planToken ?? null, operations: res.operations ?? [] }),
      false,
    )
  }

  const handleApply = async (): Promise<void> => {
    setError(null)
    setResults(null)
    await op.apply(
      (plan, signal) => assetPilotApi.organize({ objectId: plan.objectId, dryRun: false, async: plan.async, planToken: plan.planToken }, signal),
      res => {
        if (res.results != null) setResults(res.results)
        else if (res.message != null) toast.success(res.message)
      },
    )
  }

  return (
    <div>
      <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.operations.single-title')}</h4>

      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
        <input
          type="number"
          aria-label={t('asset-pilot.operations.object-id')}
          placeholder={t('asset-pilot.operations.object-id')}
          value={objectId}
          disabled={op.running || explaining}
          onChange={e => { setObjectId(e.target.value); resetReview() }}
          style={inputStyle}
        />

        <label style={toggleStyle}>
          <input type="checkbox" checked={async} disabled={op.running || explaining} onChange={e => { setAsync(e.target.checked); op.clear() }} />
          <span>{t('asset-pilot.operations.async')}</span>
        </label>

        {operate && (
          <>
            <button onClick={() => { void handlePreview() }} disabled={op.running || explaining} style={previewBtnStyle}>
              {op.running ? t('asset-pilot.operations.previewing') : t('asset-pilot.operations.preview')}
            </button>
            {op.reviewedPlan != null && (
              <button onClick={() => op.confirm()} disabled={op.running || explaining} style={primaryBtnStyle}>
                {t('asset-pilot.operations.apply-reviewed')}
              </button>
            )}
          </>
        )}

        <button onClick={() => { void handleExplain() }} disabled={op.running || explaining} style={explainBtnStyle}>
          {explaining ? t('asset-pilot.explain.loading') : t('asset-pilot.explain.button')}
        </button>
      </div>

      {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{error}</p>}

      {op.reviewedPlan != null && (
        <div>
          <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginBottom: 8 }}>
            {t('asset-pilot.operations.dry-run-result', { count: op.reviewedPlan.operations.length })}
          </p>
          {op.reviewedPlan.operations.length > 0 && (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                  <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.current-path')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.target-path')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.rule')}</th>
                </tr>
              </thead>
              <tbody>
                {op.reviewedPlan.operations.map((operation, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                    <td style={tdStyle}><OpenButton id={operation.assetId} type="asset" /></td>
                    <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{operation.sourcePath}</td>
                    <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{operation.targetPath}</td>
                    <td style={tdStyle}>{operation.ruleName}</td>
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
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }}>
          <thead>
            <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
              <th style={thStyle}>{t('asset-pilot.columns.status')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.message')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.rule')}</th>
            </tr>
          </thead>
          <tbody>
            {results.map((r, i) => (
              <tr key={i} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                <td style={tdStyle}><StatusTag status={r.status} /></td>
                <td style={tdStyle}>{r.message}</td>
                <td style={tdStyle}>{r.operation?.assetId != null ? <OpenButton id={r.operation.assetId} type="asset" /> : '-'}</td>
                <td style={tdStyle}>{r.operation?.ruleName ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {op.runId != null && <OperationRunPanel runId={op.runId} onRunIdChange={op.setRunId} />}

      {op.confirming && (
        <ConfirmDialog
          title={t('asset-pilot.confirm.organize-title')}
          description={t('asset-pilot.operations.single-confirm-description', { id: op.reviewedPlan?.objectId ?? objectId, mode: op.reviewedPlan?.async === true ? t('asset-pilot.operations.async') : t('asset-pilot.operations.sync') })}
          confirmLabel={t('asset-pilot.operations.organize')}
          variant="warning"
          loading={op.running}
          onConfirm={() => { void handleApply() }}
          onCancel={op.cancel}
        />
      )}
    </div>
  )
}

const inputStyle: React.CSSProperties = { width: 160, padding: '6px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 6, fontSize: 13, outline: 'none' }
const toggleStyle: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center', fontSize: 13, cursor: 'pointer' }
const primaryBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: 'var(--ap-color-primary)', color: 'var(--ap-color-text-light-solid)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const previewBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-primary)', borderRadius: 6, background: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const explainBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-success-border)', borderRadius: 6, background: 'var(--ap-color-success-bg)', color: 'var(--ap-color-success-text)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '6px' }
