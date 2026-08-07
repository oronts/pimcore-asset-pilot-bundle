import React from 'react'
import { useTranslation } from 'react-i18next'
import { useStorageTrends } from '../../hooks/use-asset-pilot-api'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { TrendChart } from '../shared/charts/trend-chart'
import { formatBytes, formatDate } from '../../utils/format'

const POINTS = 90

export const StorageTrendsTab: React.FC = () => {
  const { t } = useTranslation()
  const { data, loading, error, refetch } = useStorageTrends(undefined, POINTS)

  if (loading) return <TableSkeleton rows={5} columns={3} />
  if (error != null) return (
    <div>
      <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  if (data == null || data.items.length === 0) {
    return (
      <div>
        <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginBottom: 16 }}>{t('asset-pilot.storage.hint')}</p>
        <EmptyState variant="no-data" title={t('asset-pilot.storage.empty')} description={t('asset-pilot.storage.empty-desc')} />
      </div>
    )
  }

  const latest = data.items[0]
  const oldest = data.items[data.items.length - 1]
  const delta = latest.size - oldest.size

  return (
    <div>
      <h4 style={{ margin: '0 0 4px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.storage.title')}</h4>
      <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginBottom: 16 }}>{t('asset-pilot.storage.hint')}</p>

      <div style={{ display: 'flex', gap: 16, marginBottom: 20, flexWrap: 'wrap' }}>
        <SummaryCard label={t('asset-pilot.storage.current-unused')} value={formatBytes(latest.size)} sub={t('asset-pilot.storage.files', { count: latest.count })} />
        {latest.unknownSizeCount > 0 && <SummaryCard label={t('asset-pilot.storage.unknown-size')} value={String(latest.unknownSizeCount)} sub={t('asset-pilot.storage.reindex-hint')} />}
        <SummaryCard
          label={t('asset-pilot.storage.change')}
          value={`${delta > 0 ? '+' : delta < 0 ? '−' : ''}${formatBytes(Math.abs(delta))}`}
          sub={t('asset-pilot.storage.since', { date: formatDate(oldest.capturedAt) })}
          color={delta > 0 ? 'var(--ap-color-error-text)' : 'var(--ap-color-success-text)'}
        />
      </div>

      {data.items.length > 1 && (
        <div style={{ background: 'var(--ap-color-bg-container)', border: '1px solid var(--ap-color-border-secondary)', borderRadius: 8, padding: 16, marginBottom: 20 }}>
          <h5 style={{ margin: '0 0 8px', fontSize: 13, fontWeight: 600 }}>{t('asset-pilot.storage.size-trend')}</h5>
          <TrendChart
            points={[...data.items].reverse().map(p => ({ label: formatDate(p.capturedAt), value: p.size }))}
            formatValue={formatBytes}
          />
        </div>
      )}

      <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 420 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
              <th style={thStyle}>{t('asset-pilot.columns.date')}</th>
              <th style={{ ...thStyle, textAlign: 'right' }}>{t('asset-pilot.storage.unused-files')}</th>
              <th style={{ ...thStyle, textAlign: 'right' }}>{t('asset-pilot.storage.unused-size')}</th>
              <th style={{ ...thStyle, textAlign: 'right' }}>{t('asset-pilot.storage.unknown-size')}</th>
            </tr>
          </thead>
          <tbody>
            {[...data.items].reverse().map(point => (
              <tr key={point.capturedAt} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                <td style={tdStyle}>{formatDate(point.capturedAt, true)}</td>
                <td style={{ ...tdStyle, textAlign: 'right' }}>{point.count}</td>
                <td style={{ ...tdStyle, textAlign: 'right' }}>{formatBytes(point.size)}</td>
                <td style={{ ...tdStyle, textAlign: 'right' }}>{point.unknownSizeCount}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </ResponsiveTableWrapper>
    </div>
  )
}

const SummaryCard: React.FC<{ label: string; value: string; sub: string; color?: string }> = ({ label, value, sub, color }) => (
  <div style={{ background: 'var(--ap-color-fill-alter)', borderRadius: 8, padding: '12px 16px', minWidth: 160 }}>
    <div style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginBottom: 4 }}>{label}</div>
    <div style={{ fontSize: 18, fontWeight: 600, color: color ?? 'var(--ap-color-text)' }}>{value}</div>
    <div style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginTop: 2 }}>{sub}</div>
  </div>
)

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13 }
