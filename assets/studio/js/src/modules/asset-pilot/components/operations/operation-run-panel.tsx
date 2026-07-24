import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { OperationRun, OperationRunStatus } from '../../types'
import { OperationRunStatusTag } from './operation-run-status'
import { usePermissions } from '../../hooks/use-permissions'

const TERMINAL_STATUSES: ReadonlySet<OperationRunStatus> = new Set(['cancelled', 'completed', 'blocked', 'partial', 'failed'])
const CANCELLABLE_STATUSES: ReadonlySet<OperationRunStatus> = new Set(['pending_dispatch', 'queued', 'running'])
const RETRYABLE_ITEM_STATUSES = new Set(['blocked', 'cancelled', 'failed'])

interface OperationRunPanelProps {
  runId: string
  onRunIdChange?: (runId: string) => void
}

export const OperationRunPanel: React.FC<OperationRunPanelProps> = ({ runId, onRunIdChange }) => {
  const { t } = useTranslation()
  const { operate } = usePermissions()
  const [activeRunId, setActiveRunId] = useState(runId)
  const [run, setRun] = useState<OperationRun | null>(null)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [action, setAction] = useState<'cancel' | 'retry' | null>(null)
  const [pollCycle, setPollCycle] = useState(0)
  const request = useRef<AbortController | null>(null)

  useEffect(() => setActiveRunId(runId), [runId])

  const fetchRun = useCallback(async (): Promise<void> => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setLoading(true)
    setLoadError(null)

    try {
      const result = await assetPilotApi.getOperationRun(activeRunId, controller.signal)
      if (!controller.signal.aborted) setRun(result)
    } catch (error) {
      if (!(error instanceof Error && error.name === 'AbortError')) {
        setLoadError(error instanceof Error ? error.message : t('asset-pilot.common.unknown-error'))
      }
    } finally {
      if (!controller.signal.aborted) {
        setLoading(false)
        setPollCycle(cycle => cycle + 1)
      }
    }
  }, [activeRunId, t])

  useEffect(() => {
    setRun(null)
    setActionError(null)
    void fetchRun()
    return () => request.current?.abort()
  }, [fetchRun])

  useEffect(() => {
    if (run == null || loading || TERMINAL_STATUSES.has(run.status)) return

    const timer = window.setTimeout(() => { void fetchRun() }, 2000)
    return () => window.clearTimeout(timer)
  }, [fetchRun, loading, pollCycle, run])

  const displayedItems = useMemo(() => {
    if (run == null) return []
    const actionable = run.items.filter(item => item.status !== 'completed')
    return (actionable.length > 0 ? actionable : run.items).slice(0, 10)
  }, [run])

  const cancelRun = async (): Promise<void> => {
    if (run == null) return
    setAction('cancel')
    setActionError(null)

    try {
      const response = await assetPilotApi.cancelOperationRun(run.id)
      setRun(current => current == null ? current : { ...current, status: response.status })
    } catch (error) {
      setActionError(error instanceof Error ? error.message : t('asset-pilot.common.unknown-error'))
    } finally {
      setAction(null)
    }
  }

  const retryRun = async (): Promise<void> => {
    if (run == null) return
    setAction('retry')
    setActionError(null)

    try {
      const response = await assetPilotApi.retryOperationRun(run.id)
      setActiveRunId(response.runId)
      onRunIdChange?.(response.runId)
    } catch (error) {
      setActionError(error instanceof Error ? error.message : t('asset-pilot.common.unknown-error'))
    } finally {
      setAction(null)
    }
  }

  const canRetry = run != null
    && operate
    && TERMINAL_STATUSES.has(run.status)
    && run.items.some(item => RETRYABLE_ITEM_STATUSES.has(item.status))

  return (
    <section aria-labelledby={`operation-run-${activeRunId}`} style={panelStyle}>
      <div style={headerStyle}>
        <div>
          <h5 id={`operation-run-${activeRunId}`} style={{ margin: 0, fontSize: 13 }}>
            {t('asset-pilot.operation-run.title')}
          </h5>
          <code style={idStyle}>{activeRunId}</code>
        </div>
        <button type="button" onClick={() => { void fetchRun() }} disabled={loading} style={secondaryButtonStyle}>
          {loading ? t('asset-pilot.operation-run.refreshing') : t('asset-pilot.operation-run.refresh')}
        </button>
      </div>

      {loadError != null && (
        <p role="alert" style={errorStyle}>
          {t('asset-pilot.operation-run.load-failed', { message: loadError })}
        </p>
      )}

      {run == null && loadError == null && (
        <p role="status" style={mutedStyle}>{t('asset-pilot.operation-run.loading')}</p>
      )}

      {run != null && (
        <>
          <div style={summaryHeaderStyle}>
            <span aria-live="polite"><OperationRunStatusTag status={run.status} /></span>
            <span style={mutedStyle}>{t('asset-pilot.operation-run.attempt', { attempt: run.attempt })}</span>
          </div>

          <label style={progressLabelStyle}>
            <span>{t('asset-pilot.operation-run.progress', { processed: run.processedCount, total: run.totalCount })}</span>
            <progress
              value={Math.min(run.processedCount, Math.max(run.totalCount, 1))}
              max={Math.max(run.totalCount, 1)}
              aria-label={t('asset-pilot.operation-run.progress-label')}
              style={{ width: '100%', height: 10 }}
            />
          </label>

          <dl style={countsStyle}>
            <Count label={t('asset-pilot.operation-run.succeeded')} value={run.succeededCount} color="var(--ap-color-success-text)" />
            <Count label={t('asset-pilot.operation-run.skipped')} value={run.skippedCount} color="var(--ap-color-text-secondary)" />
            <Count label={t('asset-pilot.operation-run.blocked')} value={run.blockedCount} color="var(--ap-color-warning-text)" />
            <Count label={t('asset-pilot.operation-run.failed')} value={run.failedCount} color="var(--ap-color-error-text)" />
          </dl>

          {run.error != null && <p role="alert" style={errorStyle}>{run.error}</p>}
          {actionError != null && <p role="alert" style={errorStyle}>{actionError}</p>}

          {displayedItems.length > 0 && (
            <div style={{ overflowX: 'auto', marginTop: 12 }}>
              <table style={tableStyle}>
                <caption style={captionStyle}>
                  {t('asset-pilot.operation-run.items', { shown: displayedItems.length, total: run.items.length })}
                </caption>
                <thead>
                  <tr>
                    <th scope="col" style={thStyle}>{t('asset-pilot.operation-run.target')}</th>
                    <th scope="col" style={thStyle}>{t('asset-pilot.columns.status')}</th>
                    <th scope="col" style={thStyle}>{t('asset-pilot.columns.message')}</th>
                  </tr>
                </thead>
                <tbody>
                  {displayedItems.map(item => (
                    <tr key={item.key} style={{ borderTop: '1px solid var(--ap-color-border-secondary)' }}>
                      <td style={tdStyle}>{item.targetId == null ? item.key : `${item.targetType} #${item.targetId}`}</td>
                      <td style={tdStyle}>{t(`asset-pilot.operation-run.status.${item.status}`)}</td>
                      <td style={tdStyle}>{item.error ?? '-'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div style={actionsStyle}>
            {operate && CANCELLABLE_STATUSES.has(run.status) && (
              <button type="button" onClick={() => { void cancelRun() }} disabled={action != null} style={dangerButtonStyle}>
                {action === 'cancel' ? t('asset-pilot.operation-run.cancelling') : t('asset-pilot.operation-run.cancel')}
              </button>
            )}
            {canRetry && (
              <button type="button" onClick={() => { void retryRun() }} disabled={action != null} style={primaryButtonStyle}>
                {action === 'retry' ? t('asset-pilot.operation-run.retrying') : t('asset-pilot.operation-run.retry')}
              </button>
            )}
          </div>
        </>
      )}
    </section>
  )
}

const Count: React.FC<{ label: string; value: number; color: string }> = ({ label, value, color }) => (
  <div style={countStyle}>
    <dt style={mutedStyle}>{label}</dt>
    <dd style={{ margin: 0, color, fontWeight: 600 }}>{value}</dd>
  </div>
)

const panelStyle: React.CSSProperties = { border: '1px solid var(--ap-color-border)', borderRadius: 8, padding: 14, marginTop: 16, background: 'var(--ap-color-bg-container)' }
const headerStyle: React.CSSProperties = { display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }
const summaryHeaderStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, marginTop: 12 }
const idStyle: React.CSSProperties = { display: 'block', marginTop: 4, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', overflowWrap: 'anywhere' }
const mutedStyle: React.CSSProperties = { color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)' }
const errorStyle: React.CSSProperties = { color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)', margin: '10px 0 0' }
const progressLabelStyle: React.CSSProperties = { display: 'grid', gap: 4, marginTop: 12, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }
const countsStyle: React.CSSProperties = { display: 'flex', gap: 24, margin: '12px 0 0', flexWrap: 'wrap' }
const countStyle: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'baseline' }
const actionsStyle: React.CSSProperties = { display: 'flex', gap: 8, marginTop: 12 }
const tableStyle: React.CSSProperties = { width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }
const captionStyle: React.CSSProperties = { textAlign: 'left', color: 'var(--ap-color-text-secondary)', fontWeight: 600, paddingBottom: 6 }
const thStyle: React.CSSProperties = { textAlign: 'left', color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)', fontWeight: 500, padding: '6px' }
const tdStyle: React.CSSProperties = { padding: '6px', verticalAlign: 'top' }
const secondaryButtonStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 5, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }
const primaryButtonStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid var(--ap-color-primary)', borderRadius: 5, background: 'var(--ap-color-primary)', color: 'var(--ap-color-text-light-solid)', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }
const dangerButtonStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid var(--ap-color-error)', borderRadius: 5, background: 'var(--ap-color-error-bg)', color: 'var(--ap-color-error-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }
