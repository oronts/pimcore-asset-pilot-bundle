import React from 'react'
import { useTranslation } from 'react-i18next'
import { useHealth } from '../../hooks/use-asset-pilot-api'
import type { HealthStatus } from '../../types'

const STATUS_COLORS: Record<HealthStatus, string> = {
  ok: '#52c41a',
  warning: '#faad14',
  critical: '#ff4d4f',
}

const dot: React.CSSProperties = { width: 8, height: 8, borderRadius: '50%', display: 'inline-block' }

export const HealthPanel: React.FC = () => {
  const { t } = useTranslation()
  const { data, loading, error } = useHealth()

  if (loading) {
    return <p style={{ fontSize: 12, color: '#8c8c8c', margin: 0 }}>{t('asset-pilot.health.checking')}</p>
  }
  // A secondary panel stays quiet on failure; the health endpoint itself is the source of truth.
  if (error != null || data == null) {
    return null
  }

  return (
    <div style={{ background: '#fff', borderRadius: 8, padding: '16px 20px', boxShadow: '0 1px 3px rgba(0,0,0,0.06)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.health.title')}</h4>
        <span style={{ ...dot, background: STATUS_COLORS[data.status] }} />
        <span style={{ fontSize: 12, color: STATUS_COLORS[data.status], fontWeight: 600, textTransform: 'uppercase' }}>
          {t(`asset-pilot.health.status.${data.status}`)}
        </span>
      </div>
      <ul style={{ margin: 0, padding: 0, listStyle: 'none', display: 'grid', gap: 8 }}>
        {data.checks.map(check => (
          <li key={check.name} style={{ display: 'flex', alignItems: 'flex-start', gap: 8, fontSize: 13 }}>
            <span style={{ ...dot, background: STATUS_COLORS[check.status], marginTop: 5, flexShrink: 0 }} />
            <span>
              <strong style={{ fontWeight: 600 }}>{t(`asset-pilot.health.check.${check.name}`, { defaultValue: check.name })}</strong>
              <span style={{ color: '#595959' }}>{` — ${check.message}`}</span>
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
