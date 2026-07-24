import React, { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { useRules } from '../../hooks/use-asset-pilot-api'
import type { BulkPreviewResponse, MoveOperation } from '../../types'
import { OpenButton } from '../shared/open-button'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { useReviewedOperation, requireOperations } from '../../hooks/use-reviewed-operation'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { pageNumbers } from '../../utils/format'
import { OperationRunPanel } from './operation-run-panel'

interface ReviewedBulkPlan {
  className: string
  batchSize: number
  planToken: string | null
  objectCount: number
  operations: MoveOperation[]
}

export const BulkOrganizeForm: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const { data: rules, loading: rulesLoading, error: rulesError, refetch: refetchRules } = useRules()
  const [className, setClassName] = useState('')
  const [batchSize, setBatchSize] = useState('50')
  const [previewLoading, setPreviewLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [previewData, setPreviewData] = useState<BulkPreviewResponse | null>(null)
  const [previewClass, setPreviewClass] = useState<string | null>(null)
  const [previewPage, setPreviewPage] = useState(1)
  const op = useReviewedOperation<ReviewedBulkPlan>({ messageNamespace: 'asset-pilot.operations', validatePreview: requireOperations })
  const previewRequest = useRef<AbortController | null>(null)

  useEffect(() => () => previewRequest.current?.abort(), [])

  const classNames = [...new Set((rules ?? []).map(r => r.class))]

  const fetchPreview = async (page: number, requestedClass = className): Promise<void> => {
    if (requestedClass === '') {
      setError(t('asset-pilot.operations.select-a-class'))
      return
    }

    previewRequest.current?.abort()
    const controller = new AbortController()
    previewRequest.current = controller
    setPreviewLoading(true)
    setError(null)

    try {
      const res = await assetPilotApi.organizeBulkPreview(requestedClass, page, 50, controller.signal)
      if (!controller.signal.aborted) {
        setPreviewData(res)
        setPreviewClass(requestedClass)
        setPreviewPage(page)
      }
    } catch (e) {
      if (!(e instanceof Error && e.name === 'AbortError')) {
        toast.error(e instanceof Error ? e.message : t('asset-pilot.operations.preview-failed'))
      }
    } finally {
      if (!controller.signal.aborted) setPreviewLoading(false)
    }
  }

  const handlePreview = async (): Promise<void> => {
    op.clear()
    setPreviewData(null)
    setPreviewClass(null)
    setPreviewPage(1)
    await fetchPreview(1)
  }

  const handlePlanPreview = async (): Promise<void> => {
    if (previewData == null || previewClass == null || previewData.total > 1000) return
    const reviewedBatchSize = Math.max(1, parseInt(batchSize, 10) || 50)
    setError(null)
    await op.review(
      signal => assetPilotApi.organizeBulk({ className: previewClass, dryRun: true, async: true, batchSize: reviewedBatchSize }, signal).then(res => {
        if (res.objectCount == null) throw new Error(t('asset-pilot.operations.preview-invalid'))
        return res
      }),
      res => ({ className: previewClass, batchSize: reviewedBatchSize, planToken: res.planToken ?? null, objectCount: res.objectCount ?? 0, operations: res.operations ?? [] }),
      false,
    )
  }

  const handleBulkOrganize = async (): Promise<void> => {
    setError(null)
    await op.apply(
      (plan, signal) => assetPilotApi.organizeBulk({ className: plan.className, dryRun: false, async: true, batchSize: plan.batchSize, planToken: plan.planToken }, signal),
      res => {
        toast.success(t('asset-pilot.operations.bulk-queued', { objects: res.objectCount ?? 0, batches: res.batchCount ?? 0 }))
        setPreviewData(null)
        setPreviewClass(null)
      },
    )
  }

  const resetOnInput = (): void => {
    previewRequest.current?.abort()
    setPreviewData(null)
    setPreviewClass(null)
    setPreviewLoading(false)
    setError(null)
    op.clear()
  }

  return (
    <div>
      <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.operations.bulk-title')}</h4>

      {rulesError != null && (
        <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }}>
          {t('asset-pilot.operations.rules-failed', { message: rulesError })} <button onClick={refetchRules}>{t('asset-pilot.common.retry')}</button>
        </p>
      )}

      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
        <select
          aria-label={t('asset-pilot.operations.select-class')}
          value={className}
          disabled={rulesLoading || rulesError != null || op.running}
          onChange={e => { setClassName(e.target.value); resetOnInput() }}
          style={selectStyle}
        >
          <option value="">{t('asset-pilot.operations.select-class')}</option>
          {classNames.map(c => <option key={c} value={c}>{c}</option>)}
        </select>

        <input
          type="number"
          aria-label={t('asset-pilot.operations.batch-size')}
          value={batchSize}
          disabled={op.running}
          onChange={e => { setBatchSize(e.target.value); op.clear() }}
          placeholder={t('asset-pilot.operations.batch-size')}
          style={{ ...inputStyle, width: 100 }}
          min={1}
          max={500}
        />

        <button
          onClick={() => { void handlePreview() }}
          disabled={previewLoading || className === '' || rulesLoading || rulesError != null}
          style={previewBtnStyle}
        >
          {previewLoading ? t('asset-pilot.operations.previewing') : t('asset-pilot.operations.browse-candidates')}
        </button>
      </div>

      {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{error}</p>}

      {previewData != null && (
        <div style={{ marginBottom: 12 }}>
          <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginBottom: 8 }}>
            {t('asset-pilot.operations.preview-count', { count: previewData.total })}
          </p>

          {previewData.objects.length > 0 && (
            <>
              <div style={{ overflowX: 'auto', maxHeight: 400, overflowY: 'auto', marginBottom: 12 }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                      <th style={thStyle}>{t('asset-pilot.columns.id')}</th>
                      <th style={thStyle}>{t('asset-pilot.columns.object-name')}</th>
                      <th style={thStyle}>{t('asset-pilot.columns.class')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {previewData.objects.map(obj => (
                      <tr key={obj.id} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                        <td style={tdStyle}><OpenButton id={obj.id} type="data-object" /></td>
                        <td style={{ ...tdStyle, fontWeight: 500 }}>{obj.key}</td>
                        <td style={tdStyle}>{obj.className}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {previewData.pages > 1 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 4, marginBottom: 12 }}>
                  <button
                    onClick={() => { void fetchPreview(previewPage - 1) }}
                    disabled={previewPage <= 1 || previewLoading}
                    style={pageBtnStyle}
                  >
                    {t('asset-pilot.common.prev')}
                  </button>
                  {pageNumbers(previewPage, previewData.pages).map(p => (
                    <button
                      key={p}
                      onClick={() => { void fetchPreview(p) }}
                      disabled={previewLoading}
                      aria-current={p === previewPage ? 'page' : undefined}
                      aria-label={t('asset-pilot.common.page-number', { page: p })}
                      style={{
                        ...pageBtnStyle,
                        background: p === previewPage ? 'var(--ap-color-primary)' : 'var(--ap-color-bg-container)',
                        color: p === previewPage ? 'var(--ap-color-bg-container)' : 'var(--ap-color-text-secondary)',
                        fontWeight: p === previewPage ? 600 : 400,
                      }}
                    >
                      {p}
                    </button>
                  ))}
                  <button
                    onClick={() => { void fetchPreview(previewPage + 1) }}
                    disabled={previewPage >= previewData.pages || previewLoading}
                    style={pageBtnStyle}
                  >
                    {t('asset-pilot.common.next')}
                  </button>
                  <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginLeft: 8 }}>
                    {t('asset-pilot.common.page-info', { page: previewPage, pages: previewData.pages })}
                  </span>
                </div>
              )}

              {previewData.total > 1000 && (
                <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }}>
                  {t('asset-pilot.operations.bulk-limit', { count: previewData.total, max: 1000 })}
                </p>
              )}

              {operate && op.reviewedPlan == null && (
                <button onClick={() => { void handlePlanPreview() }} disabled={op.running || previewData.total > 1000 || previewClass == null} style={previewBtnStyle}>
                  {op.running ? t('asset-pilot.operations.previewing') : t('asset-pilot.operations.review-organization')}
                </button>
              )}
            </>
          )}
        </div>
      )}

      {op.reviewedPlan != null && (
        <div style={{ marginBottom: 12 }}>
          <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>
            {t('asset-pilot.operations.reviewed-bulk-plan', { objects: op.reviewedPlan.objectCount, operations: op.reviewedPlan.operations.length })}
          </p>
          {op.reviewedPlan.operations.length > 0 && (
            <div style={{ overflowX: 'auto', maxHeight: 320, overflowY: 'auto', marginBottom: 12 }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }}>
                <thead>
                  <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                    <th style={thStyle}>{t('asset-pilot.columns.object')}</th>
                    <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                    <th style={thStyle}>{t('asset-pilot.columns.current-path')}</th>
                    <th style={thStyle}>{t('asset-pilot.columns.target-path')}</th>
                  </tr>
                </thead>
                <tbody>
                  {op.reviewedPlan.operations.map((operation, index) => (
                    <tr key={`${operation.objectId ?? 'object'}-${operation.assetId}-${index}`} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                      <td style={tdStyle}>{operation.objectId ?? '-'}</td>
                      <td style={tdStyle}><OpenButton id={operation.assetId} type="asset" /></td>
                      <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{operation.sourcePath}</td>
                      <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{operation.targetPath}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <button onClick={() => op.confirm()} disabled={op.running} style={warnBtnStyle}>
            {t('asset-pilot.operations.organize-reviewed')}
          </button>
        </div>
      )}

      {op.runId != null && <OperationRunPanel runId={op.runId} onRunIdChange={op.setRunId} />}

      {op.confirming && (
        <ConfirmDialog
          title={t('asset-pilot.confirm.organize-title')}
          description={t('asset-pilot.operations.bulk-confirm-description', { className: op.reviewedPlan?.className, count: op.reviewedPlan?.objectCount ?? 0 })}
          confirmLabel={t('asset-pilot.common.confirm')}
          variant="warning"
          loading={op.running}
          onConfirm={() => { void handleBulkOrganize() }}
          onCancel={op.cancel}
        />
      )}
    </div>
  )
}

const selectStyle: React.CSSProperties = {
  padding: '6px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 6, fontSize: 13, outline: 'none', minWidth: 180,
}
const inputStyle: React.CSSProperties = { padding: '6px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 6, fontSize: 13, outline: 'none' }
const previewBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-primary)', borderRadius: 6, background: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const warnBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-warning-border-hover)', borderRadius: 6, background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const pageBtnStyle: React.CSSProperties = {
  padding: '4px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text-secondary)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)',
}
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '6px' }
