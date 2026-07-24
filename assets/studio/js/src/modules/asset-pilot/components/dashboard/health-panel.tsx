import React from 'react'
import { useTranslation } from 'react-i18next'
import { useHealth } from '../../hooks/use-asset-pilot-api'
import type { HealthStatus } from '../../types'
import { humanizeIdentifier } from '../../utils/format'

const STATUS_COLORS: Record<HealthStatus, string> = {
  ok: 'var(--ap-color-success-text)',
  warning: 'var(--ap-color-warning-text)',
  critical: 'var(--ap-color-error-text)',
}

const dot: React.CSSProperties = { width: 8, height: 8, borderRadius: '50%', display: 'inline-block' }

export const HealthPanel: React.FC = () => {
  const { t } = useTranslation()
  const { data, loading, error, refetch } = useHealth()

  if (loading) {
    return <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', margin: 0 }}>{t('asset-pilot.health.checking')}</p>
  }
  if (error != null || data == null) {
    return (
      <div role="alert" style={{ background: 'var(--ap-color-error-bg)', border: '1px solid var(--ap-color-error-border)', borderRadius: 8, padding: '12px 16px' }}>
        <p style={{ margin: '0 0 8px', color: 'var(--ap-color-error-text)', fontSize: 'var(--ap-font-size)' }}>{t('asset-pilot.health.failed', { message: error ?? t('asset-pilot.common.unknown-error') })}</p>
        <button type="button" onClick={refetch}>{t('asset-pilot.common.retry')}</button>
      </div>
    )
  }

  return (
    <div style={{ background: 'var(--ap-color-bg-container)', borderRadius: 8, padding: '16px 20px', boxShadow: 'var(--ap-box-shadow-secondary)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.health.title')}</h4>
        <span style={{ ...dot, background: STATUS_COLORS[data.status] }} />
        <span style={{ fontSize: 'var(--ap-font-size)', color: STATUS_COLORS[data.status], fontWeight: 600, textTransform: 'uppercase' }}>
          {t(`asset-pilot.health.status.${data.status}`)}
        </span>
      </div>
      <ul style={{ margin: 0, padding: 0, listStyle: 'none', display: 'grid', gap: 8 }}>
        {data.checks.map(check => (
          <li key={check.name} style={{ display: 'flex', alignItems: 'flex-start', gap: 8, fontSize: 13 }}>
            <span style={{ ...dot, background: STATUS_COLORS[check.status], marginTop: 5, flexShrink: 0 }} />
            <span style={{ minWidth: 0 }}>
              <strong style={{ fontWeight: 600 }}>{t(`asset-pilot.health.check.${check.name}`, { defaultValue: humanizeIdentifier(check.name) })}</strong>
              <span style={{ color: 'var(--ap-color-text-secondary)' }}>{` — ${check.message}`}</span>
              {check.details != null && Object.keys(check.details).length > 0 && (
                <details style={{ marginTop: 4 }}>
                  <summary style={{ cursor: 'pointer', color: 'var(--ap-color-primary)' }}>{t('asset-pilot.health.details')}</summary>
                  <pre style={detailsStyle}>{JSON.stringify(check.details, null, 2)}</pre>
                </details>
              )}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

const detailsStyle: React.CSSProperties = {
  margin: '6px 0 0',
  maxWidth: '100%',
  maxHeight: 240,
  overflow: 'auto',
  padding: 8,
  borderRadius: 4,
  background: 'var(--ap-color-bg-layout)',
  color: 'var(--ap-color-text)',
  fontSize: 12,
  whiteSpace: 'pre-wrap',
  overflowWrap: 'anywhere',
}
