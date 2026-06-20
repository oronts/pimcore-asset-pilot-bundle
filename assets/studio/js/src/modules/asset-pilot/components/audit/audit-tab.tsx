import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAudit } from '../../hooks/use-asset-pilot-api'
import { assetPilotApi } from '../../services/api'
import type { AuditEntry, AuditFilters } from '../../types'
import { StatusTag, TriggerTag } from '../shared/status-tag'
import { OpenButton } from '../shared/open-button'
import { AuditFiltersBar } from './audit-filters'
import { RevertConfirmModal } from './revert-confirm-modal'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { SortableHeader } from '../shared/sortable-header'
import { useSort } from '../../hooks/use-sort'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { useContainerWidth } from '../../hooks/use-container-width'
import { formatDate } from '../../utils/format'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { getVisibleColumns, type ColumnConfig } from '../../utils/column-visibility'

const columns: ColumnConfig[] = [
  { key: 'id', priority: 1 },
  { key: 'asset', priority: 1 },
  { key: 'from', priority: 2 },
  { key: 'to', priority: 2 },
  { key: 'object', priority: 1 },
  { key: 'class', priority: 2 },
  { key: 'rule', priority: 1 },
  { key: 'trigger', priority: 3 },
  { key: 'status', priority: 1 },
  { key: 'duration', priority: 3 },
  { key: 'date', priority: 1 },
  { key: 'actions', priority: 1 },
]

export const AuditTab: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { admin } = usePermissions()
  const { sortField, sortDirection, toggleSort, sortParams } = useSort<AuditEntry>()
  const [filters, setFilters] = useState<AuditFilters>({ page: 1, limit: 20 })
  const mergedFilters = { ...filters, ...sortParams }
  const { data, loading, error, refetch } = useAudit(mergedFilters)
  const [revertEntry, setRevertEntry] = useState<AuditEntry | null>(null)
  const [containerRef, containerWidth] = useContainerWidth()
  const visible = getVisibleColumns(columns, containerWidth)

  const handleExport = (): void => {
    assetPilotApi.exportAudit(filters)
  }

  const handleReverted = (): void => {
    setRevertEntry(null)
    toast.success(t('asset-pilot.revert.confirm'))
    refetch()
  }

  const hasFilters = filters.class != null || filters.status != null || filters.ruleName != null

  return (
    <div ref={containerRef}>
      <AuditFiltersBar filters={filters} onChange={setFilters} onExport={handleExport} />

      {loading && <TableSkeleton rows={5} columns={8} />}
      {error != null && <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>}

      {!loading && data != null && (
        <>
          <div style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>
            {t('asset-pilot.common.showing', { count: data.items.length, total: data.total })} {t('asset-pilot.audit.entries')} — {t('asset-pilot.common.page-info', { page: data.page, pages: data.pages })}
          </div>

          {data.items.length === 0 ? (
            <EmptyState
              variant={hasFilters ? 'no-results' : 'no-data'}
              title={hasFilters ? t('asset-pilot.empty.no-results-title') : t('asset-pilot.empty.no-data-title')}
              description={hasFilters ? t('asset-pilot.empty.no-results-desc') : t('asset-pilot.empty.no-data-desc')}
              action={hasFilters ? { label: t('asset-pilot.empty.clear-filters'), onClick: () => setFilters({ page: 1, limit: 20 }) } : undefined}
            />
          ) : (
            <ResponsiveTableWrapper stickyFirstColumn tableId="audit">
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, minWidth: 900 }}>
                <thead>
                  <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                    {visible.has('id') && <SortableHeader label={t('asset-pilot.columns.id')} field="id" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('asset') && <SortableHeader label={t('asset-pilot.columns.asset')} field="asset_id" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('from') && <th style={thStyle}>{t('asset-pilot.columns.from')}</th>}
                    {visible.has('to') && <th style={thStyle}>{t('asset-pilot.columns.to')}</th>}
                    {visible.has('object') && <SortableHeader label={t('asset-pilot.columns.object')} field="object_id" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('class') && <SortableHeader label={t('asset-pilot.columns.class')} field="object_class" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('rule') && <SortableHeader label={t('asset-pilot.columns.rule')} field="rule_name" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('trigger') && <th style={thStyle}>{t('asset-pilot.columns.trigger')}</th>}
                    {visible.has('status') && <SortableHeader label={t('asset-pilot.columns.status')} field="status" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('duration') && <SortableHeader label={t('asset-pilot.columns.duration')} field="duration_ms" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('date') && <SortableHeader label={t('asset-pilot.columns.date')} field="created_at" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('actions') && <th style={thStyle}>{t('asset-pilot.common.actions')}</th>}
                  </tr>
                </thead>
                <tbody>
                  {data.items.map(entry => (
                    <tr key={entry.id} style={{ borderBottom: '1px solid #f5f5f5' }}>
                      {visible.has('id') && <td style={tdStyle}>{entry.id}</td>}
                      {visible.has('asset') && <td style={tdStyle}><OpenButton id={entry.asset_id} type="asset" /></td>}
                      {visible.has('from') && <td style={tdStyle}><ExpandablePath path={entry.asset_path_from} maxLength={45} /></td>}
                      {visible.has('to') && <td style={tdStyle}><ExpandablePath path={entry.asset_path_to} maxLength={45} /></td>}
                      {visible.has('object') && <td style={tdStyle}><OpenButton id={entry.object_id} type="data-object" /></td>}
                      {visible.has('class') && <td style={tdStyle}>{entry.object_class}</td>}
                      {visible.has('rule') && <td style={tdStyle}>{entry.rule_name}</td>}
                      {visible.has('trigger') && <td style={tdStyle}><TriggerTag trigger={entry.trigger_type} /></td>}
                      {visible.has('status') && <td style={tdStyle}><StatusTag status={entry.status} /></td>}
                      {visible.has('duration') && <td style={tdStyle}>{entry.duration_ms != null ? `${entry.duration_ms}ms` : '-'}</td>}
                      {visible.has('date') && <td style={{ ...tdStyle, fontSize: 11, color: '#8c8c8c' }}>{formatDate(entry.created_at)}</td>}
                      {visible.has('actions') && (
                        <td style={tdStyle}>
                          {admin && entry.status === 'completed' && (
                            <button onClick={() => setRevertEntry(entry)} style={revertBtnStyle}>{t('asset-pilot.audit.revert')}</button>
                          )}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </ResponsiveTableWrapper>
          )}

          <Pagination
            page={data.page}
            pages={data.pages}
            onPage={p => setFilters(f => ({ ...f, page: p }))}
            limit={filters.limit}
            onLimit={n => setFilters(f => ({ ...f, limit: n, page: 1 }))}
          />
        </>
      )}

      {revertEntry != null && (
        <RevertConfirmModal entry={revertEntry} onClose={() => setRevertEntry(null)} onReverted={handleReverted} />
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px 4px', fontSize: 11, color: '#8c8c8c', fontWeight: 500, whiteSpace: 'nowrap' }
const tdStyle: React.CSSProperties = { padding: '6px 4px' }
const revertBtnStyle: React.CSSProperties = {
  padding: '2px 8px', border: '1px solid #fa8c16', borderRadius: 4, background: '#fff7e6', color: '#fa8c16',
  cursor: 'pointer', fontSize: 11, fontWeight: 500,
}
