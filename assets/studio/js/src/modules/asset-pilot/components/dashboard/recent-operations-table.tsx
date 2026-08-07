import React from 'react'
import { useTranslation } from 'react-i18next'
import type { AuditEntry } from '../../types'
import { StatusTag } from '../shared/status-tag'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { SortableHeader } from '../shared/sortable-header'
import { useSort } from '../../hooks/use-sort'
import { truncate, formatDate } from '../../utils/format'

interface RecentOperationsTableProps {
  operations: AuditEntry[]
  onViewAll: () => void
}

export const RecentOperationsTable: React.FC<RecentOperationsTableProps> = ({ operations, onViewAll }) => {
  const { t } = useTranslation()
  const { sortField, sortDirection, toggleSort, sortedData } = useSort<AuditEntry>()

  if (operations.length === 0) {
    return <EmptyState variant="no-data" title={t('asset-pilot.dashboard.no-recent')} />
  }

  const sorted = sortedData(operations).slice(0, 10)

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600, color: 'var(--ap-color-text)' }}>{t('asset-pilot.dashboard.recent-operations')}</h4>
        <button onClick={onViewAll} style={viewAllStyle}>{t('asset-pilot.dashboard.view-all')}</button>
      </div>
      <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
              <SortableHeader label={t('asset-pilot.columns.asset')} field="asset_id" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />
              <SortableHeader label={t('asset-pilot.columns.from')} field="asset_path_from" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />
              <SortableHeader label={t('asset-pilot.columns.to')} field="asset_path_to" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />
              <SortableHeader label={t('asset-pilot.columns.rule')} field="rule_name" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />
              <SortableHeader label={t('asset-pilot.columns.status')} field="status" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />
              <SortableHeader label={t('asset-pilot.columns.date')} field="created_at" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />
            </tr>
          </thead>
          <tbody>
            {sorted.map((op, i) => (
              <tr key={op.id ?? i} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                <td style={tdStyle}>{op.asset_id}</td>
                <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }} title={op.asset_path_from}>{truncate(op.asset_path_from, 30)}</td>
                <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }} title={op.asset_path_to}>{truncate(op.asset_path_to, 30)}</td>
                <td style={tdStyle}>{op.rule_name}</td>
                <td style={tdStyle}><StatusTag status={op.status} /></td>
                <td style={{ ...tdStyle, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{formatDate(op.created_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </ResponsiveTableWrapper>
    </div>
  )
}

const viewAllStyle: React.CSSProperties = {
  border: 'none', background: 'none', color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const tdStyle: React.CSSProperties = { padding: '6px' }
