import React, { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { OperationRunSummary } from '../../types'
import { formatDate } from '../../utils/format'
import { visuallyHiddenStyle } from '../shared/visually-hidden-style'
import { OperationRunPanel } from './operation-run-panel'
import { OperationRunStatusTag } from './operation-run-status'

const HISTORY_LIMIT = 20

export const RecentOperationRuns: React.FC = () => {
  const { t } = useTranslation()
  const titleId = useId()
  const [runs, setRuns] = useState<OperationRunSummary[] | null>(null)
  const [selectedRunId, setSelectedRunId] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const request = useRef<AbortController | null>(null)
  const requestId = useRef(0)

  const fetchRuns = useCallback(async (): Promise<void> => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    const id = ++requestId.current
    setLoading(true)

    try {
      const response = await assetPilotApi.getOperationRuns(HISTORY_LIMIT, controller.signal)
      if (id === requestId.current) {
        setRuns(response.items)
        setError(null)
      }
    } catch (loadError) {
      if (id === requestId.current && !(loadError instanceof Error && loadError.name === 'AbortError')) {
        setError(loadError instanceof Error ? loadError.message : t('asset-pilot.common.unknown-error'))
      }
    } finally {
      if (id === requestId.current) setLoading(false)
    }
  }, [t])

  useEffect(() => {
    void fetchRuns()
    return () => request.current?.abort()
  }, [fetchRuns])
  const handleRunIdChange = useCallback((nextRunId: string): void => {
    setSelectedRunId(nextRunId)
    void fetchRuns()
  }, [fetchRuns])

  return (
    <section aria-labelledby={titleId}>
      <div style={headerStyle}>
        <div>
          <h4 id={titleId} style={titleStyle}>{t('asset-pilot.operation-history.title')}</h4>
          <p style={descriptionStyle}>{t('asset-pilot.operation-history.description')}</p>
        </div>
        <button type="button" onClick={() => { void fetchRuns() }} disabled={loading} style={secondaryButtonStyle}>
          {loading ? t('asset-pilot.operation-history.refreshing') : t('asset-pilot.operation-history.refresh')}
        </button>
      </div>

      {error != null && (
        <p role="alert" style={errorStyle}>
          {t('asset-pilot.operation-history.load-failed', { message: error })}
        </p>
      )}

      {runs == null && error == null && (
        <p role="status" style={mutedStyle}>{t('asset-pilot.operation-history.loading')}</p>
      )}

      {runs?.length === 0 && (
        <p role="status" style={mutedStyle}>{t('asset-pilot.operation-history.empty')}</p>
      )}

      {runs != null && runs.length > 0 && (
        <div style={tableContainerStyle}>
          <table style={tableStyle}>
            <caption style={visuallyHiddenStyle}>{t('asset-pilot.operation-history.table')}</caption>
            <thead>
              <tr>
                <th scope="col" style={thStyle}>{t('asset-pilot.operation-history.kind')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.columns.status')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.operation-history.progress')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.operation-history.updated')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.operation-history.details')}</th>
              </tr>
            </thead>
            <tbody>
              {runs.map(run => {
                const isSelected = selectedRunId === run.id
                return (
                  <tr key={run.id} style={isSelected ? selectedRowStyle : rowStyle}>
                    <td style={tdStyle}><code style={kindStyle}>{run.kind}</code></td>
                    <td style={tdStyle}><OperationRunStatusTag status={run.status} /></td>
                    <td style={tdStyle}>
                      <div style={progressStyle}>
                        <span>{run.processedCount}/{run.totalCount}</span>
                        <progress
                          value={Math.min(run.processedCount, Math.max(run.totalCount, 1))}
                          max={Math.max(run.totalCount, 1)}
                          aria-label={t('asset-pilot.operation-history.progress-label', {
                            id: run.id,
                            processed: run.processedCount,
                            total: run.totalCount,
                          })}
                          style={{ width: 72, height: 8 }}
                        />
                      </div>
                    </td>
                    <td style={tdStyle}><time dateTime={run.updatedAt}>{formatDate(run.updatedAt)}</time></td>
                    <td style={tdStyle}>
                      <button
                        type="button"
                        aria-label={t('asset-pilot.operation-history.open-label', { id: run.id })}
                        aria-pressed={isSelected}
                        onClick={() => setSelectedRunId(run.id)}
                        style={secondaryButtonStyle}
                      >
                        {t('asset-pilot.operation-history.open')}
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {selectedRunId != null && (
        <OperationRunPanel key={selectedRunId} runId={selectedRunId} onRunIdChange={handleRunIdChange} />
      )}
    </section>
  )
}

const headerStyle: React.CSSProperties = { display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }
const titleStyle: React.CSSProperties = { margin: 0, fontSize: 14, fontWeight: 600 }
const descriptionStyle: React.CSSProperties = { margin: '4px 0 0', color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)' }
const mutedStyle: React.CSSProperties = { color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)' }
const errorStyle: React.CSSProperties = { color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)', margin: '10px 0 0' }
const tableContainerStyle: React.CSSProperties = { overflowX: 'auto', marginTop: 12 }
const tableStyle: React.CSSProperties = { width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }
const rowStyle: React.CSSProperties = { borderTop: '1px solid var(--ap-color-border-secondary)' }
const selectedRowStyle: React.CSSProperties = { ...rowStyle, background: 'var(--ap-color-primary-bg)' }
const thStyle: React.CSSProperties = { textAlign: 'left', color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)', fontWeight: 600, padding: '8px 6px' }
const tdStyle: React.CSSProperties = { padding: '8px 6px', verticalAlign: 'middle' }
const kindStyle: React.CSSProperties = { color: 'var(--ap-color-text)', fontSize: 'var(--ap-font-size)' }
const progressStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, whiteSpace: 'nowrap' }
const secondaryButtonStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 5, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', whiteSpace: 'nowrap' }
