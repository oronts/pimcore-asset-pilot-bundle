import React, { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'

interface StatusData {
  stats: Record<string, number | Record<string, number>>
  recentOperations: unknown[]
}

export const OperationStatus: React.FC = () => {
  const { t } = useTranslation()
  const [status, setStatus] = useState<StatusData | null>(null)
  const [loading, setLoading] = useState(false)

  const fetchStatus = useCallback(async () => {
    setLoading(true)
    try {
      const data = await assetPilotApi.getStatus()
      setStatus(data)
    } catch {
      // Silently handle status poll errors
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void fetchStatus()
    const interval = setInterval(() => { void fetchStatus() }, 5000)
    return () => clearInterval(interval)
  }, [fetchStatus])

  if (status == null) return null

  const stats = status.stats

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.operations.system-status')}</h4>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {loading && <span style={{ fontSize: 11, color: '#8c8c8c' }}>{t('asset-pilot.operations.refreshing')}</span>}
          <button onClick={() => { void fetchStatus() }} style={refreshBtnStyle}>{t('asset-pilot.operations.refresh')}</button>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
        {Object.entries(stats)
          .filter(([, val]) => typeof val !== 'object')
          .map(([key, val]) => (
            <div key={key} style={{ background: '#fafafa', borderRadius: 6, padding: '8px 16px', fontSize: 13 }}>
              <span style={{ color: '#8c8c8c', marginRight: 8 }}>{key}:</span>
              <strong>{val as number}</strong>
            </div>
          ))}
      </div>
    </div>
  )
}

const refreshBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff',
  cursor: 'pointer', fontSize: 12,
}
