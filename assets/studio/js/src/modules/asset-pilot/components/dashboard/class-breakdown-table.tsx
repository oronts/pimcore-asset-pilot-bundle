import React from 'react'
import { useTranslation } from 'react-i18next'
import type { ClassStat } from '../../types'
import { useSort } from '../../hooks/use-sort'
import { SortableHeader } from '../shared/sortable-header'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'

interface ClassBreakdownTableProps {
  stats: ClassStat[]
  loading: boolean
}

const statusColors: Record<string, string> = {
  completed: 'var(--ap-color-success-text)',
  failed: 'var(--ap-color-error-text)',
  skipped: 'var(--ap-color-text-secondary)',
}

export const ClassBreakdownTable: React.FC<ClassBreakdownTableProps> = ({ stats, loading }) => {
  const { t } = useTranslation()
  const { sortField, sortDirection, toggleSort, sortedData } = useSort<ClassStat>()

  if (loading) return <TableSkeleton rows={3} columns={6} />

  if (stats.length === 0) {
    return <EmptyState variant="no-data" title={t('asset-pilot.dashboard.no-operations')} description={t('asset-pilot.empty.no-data-desc')} />
  }

  const sorted = sortedData(stats)

  return (
    <div>
      <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 600, color: 'var(--ap-color-text)' }}>{t('asset-pilot.dashboard.class-breakdown')}</h4>
      <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
              <SortableHeader label={t('asset-pilot.columns.class')} field="className" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />
              <SortableHeader label={t('asset-pilot.columns.total')} field="total" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ textAlign: 'right' }} />
              <SortableHeader label={t('asset-pilot.columns.completed')} field="completed" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ textAlign: 'right' }} />
              <SortableHeader label={t('asset-pilot.columns.failed')} field="failed" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ textAlign: 'right' }} />
              <SortableHeader label={t('asset-pilot.columns.skipped')} field="skipped" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ textAlign: 'right' }} />
              <SortableHeader label={t('asset-pilot.columns.rules')} field="ruleCount" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ textAlign: 'right' }} />
            </tr>
          </thead>
          <tbody>
            {sorted.map(stat => (
              <tr key={stat.className} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                <td style={{ ...tdStyle, fontWeight: 500 }}>{stat.className}</td>
                <td style={{ ...tdStyle, textAlign: 'right' }}>{stat.total}</td>
                <td style={{ ...tdStyle, textAlign: 'right', color: statusColors.completed }}>{stat.completed}</td>
                <td style={{ ...tdStyle, textAlign: 'right', color: statusColors.failed }}>{stat.failed}</td>
                <td style={{ ...tdStyle, textAlign: 'right', color: statusColors.skipped }}>{stat.skipped}</td>
                <td style={{ ...tdStyle, textAlign: 'right' }}>{stat.ruleCount}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </ResponsiveTableWrapper>
    </div>
  )
}

const tdStyle: React.CSSProperties = { padding: '8px 6px' }
