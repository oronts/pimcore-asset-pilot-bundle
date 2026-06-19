import React from 'react'
import { useTranslation } from 'react-i18next'
import { useStorageTrends } from '../../hooks/use-asset-pilot-api'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { formatBytes, formatDate } from '../../utils/format'

const POINTS = 90

export const StorageTrendsTab: React.FC = () => {
  const { t } = useTranslation()
  const { data, loading, error, refetch } = useStorageTrends(undefined, POINTS)

  if (loading) return <TableSkeleton rows={5} columns={3} />
  if (error != null) return (
    <div>
      <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  if (data == null || data.items.length === 0) {
    return (
      <div>
        <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 16 }}>{t('asset-pilot.storage.hint')}</p>
        <EmptyState variant="no-data" title={t('asset-pilot.storage.empty')} description={t('asset-pilot.storage.empty-desc')} />
      </div>
    )
  }

  const latest = data.items[data.items.length - 1]
  const first = data.items[0]
  const delta = latest.size - first.size

  return (
    <div>
      <h4 style={{ margin: '0 0 4px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.storage.title')}</h4>
      <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 16 }}>{t('asset-pilot.storage.hint')}</p>

      <div style={{ display: 'flex', gap: 16, marginBottom: 20, flexWrap: 'wrap' }}>
        <SummaryCard label={t('asset-pilot.storage.current-unused')} value={formatBytes(latest.size)} sub={t('asset-pilot.storage.files', { count: latest.count })} />
        <SummaryCard
          label={t('asset-pilot.storage.change')}
          value={`${delta >= 0 ? '+' : ''}${formatBytes(Math.abs(delta))}`}
          sub={t('asset-pilot.storage.since', { date: formatDate(first.capturedAt) })}
          color={delta > 0 ? '#ff4d4f' : '#52c41a'}
        />
      </div>

      <ResponsiveTableWrapper>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 420 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
              <th style={thStyle}>{t('asset-pilot.columns.date')}</th>
              <th style={{ ...thStyle, textAlign: 'right' }}>{t('asset-pilot.storage.unused-files')}</th>
              <th style={{ ...thStyle, textAlign: 'right' }}>{t('asset-pilot.storage.unused-size')}</th>
            </tr>
          </thead>
          <tbody>
            {[...data.items].reverse().map(point => (
              <tr key={point.capturedAt} style={{ borderBottom: '1px solid #f5f5f5' }}>
                <td style={tdStyle}>{formatDate(point.capturedAt, true)}</td>
                <td style={{ ...tdStyle, textAlign: 'right' }}>{point.count}</td>
                <td style={{ ...tdStyle, textAlign: 'right' }}>{formatBytes(point.size)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </ResponsiveTableWrapper>
    </div>
  )
}

const SummaryCard: React.FC<{ label: string; value: string; sub: string; color?: string }> = ({ label, value, sub, color }) => (
  <div style={{ background: '#fafafa', borderRadius: 8, padding: '12px 16px', minWidth: 160 }}>
    <div style={{ fontSize: 11, color: '#8c8c8c', marginBottom: 4 }}>{label}</div>
    <div style={{ fontSize: 18, fontWeight: 600, color: color ?? '#1a1a1a' }}>{value}</div>
    <div style={{ fontSize: 11, color: '#8c8c8c', marginTop: 2 }}>{sub}</div>
  </div>
)

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 12, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13 }
