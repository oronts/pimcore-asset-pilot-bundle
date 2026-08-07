import React, { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { AuditEntry } from '../../types'
import { StatusTag } from '../shared/status-tag'
import { OpenButton } from '../shared/open-button'
import { formatDate, humanizeIdentifier } from '../../utils/format'

interface StatusData {
  stats: Record<string, number | Record<string, number>>
  recentOperations: AuditEntry[]
}

export const OperationStatus: React.FC = () => {
  const { t } = useTranslation()
  const [status, setStatus] = useState<StatusData | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null)
  const request = useRef<AbortController | null>(null)
  const requestId = useRef(0)

  const fetchStatus = useCallback(async () => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    const id = ++requestId.current
    setLoading(true)
    try {
      const data = await assetPilotApi.getStatus(controller.signal)
      if (id === requestId.current) {
        setStatus(data)
        setError(null)
        setLastUpdated(new Date())
      }
    } catch (e) {
      if (id === requestId.current && !(e instanceof Error && e.name === 'AbortError')) {
        setError(e instanceof Error ? e.message : t('asset-pilot.operations.status-failed'))
      }
    } finally {
      if (id === requestId.current) setLoading(false)
    }
  }, [t])

  useEffect(() => {
    let disposed = false
    let timer: ReturnType<typeof setTimeout> | undefined
    const poll = async (): Promise<void> => {
      await fetchStatus()
      if (!disposed) timer = setTimeout(() => { void poll() }, 5000)
    }
    void poll()
    return () => {
      disposed = true
      if (timer != null) clearTimeout(timer)
      request.current?.abort()
    }
  }, [fetchStatus])

  if (status == null && loading) return <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.common.loading')}</p>
  if (status == null && error != null) return (
    <div role="alert">
      <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={() => { void fetchStatus() }} style={refreshBtnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )
  if (status == null) return null

  const stats = status.stats

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.operations.system-status')}</h4>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {loading && <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.operations.refreshing')}</span>}
          {lastUpdated != null && <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.operations.last-updated', { date: lastUpdated.toLocaleTimeString() })}</span>}
          <button onClick={() => { void fetchStatus() }} style={refreshBtnStyle}>{t('asset-pilot.operations.refresh')}</button>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
        {Object.entries(stats)
          .filter(([, val]) => typeof val !== 'object')
          .map(([key, val]) => (
            <div key={key} style={{ background: 'var(--ap-color-fill-alter)', borderRadius: 6, padding: '8px 16px', fontSize: 13 }}>
              <span style={{ color: 'var(--ap-color-text-secondary)', marginRight: 8 }}>{t(`asset-pilot.operations.stat.${key}`, { defaultValue: humanizeIdentifier(key) })}:</span>
              <strong>{val as number}</strong>
            </div>
          ))}
      </div>

      {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }}>{t('asset-pilot.operations.stale-status', { message: error })}</p>}

      {status.recentOperations.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <h5 style={{ margin: '0 0 8px', fontSize: 13 }}>{t('asset-pilot.dashboard.recent-operations')}</h5>
          <div style={{ display: 'grid', gap: 6 }}>
            {status.recentOperations.slice(0, 5).map(operation => (
              <div key={operation.id} style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 'var(--ap-font-size)' }}>
                <StatusTag status={operation.status} />
                <OpenButton id={operation.asset_id} type="asset" />
                <span>{operation.rule_name}</span>
                <span style={{ marginLeft: 'auto', color: 'var(--ap-color-text-secondary)' }}>{formatDate(operation.created_at)}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

const refreshBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)',
}
