import React from 'react'
import { ResponsiveTableWrapper } from './responsive-table-wrapper'
import { SortableHeader } from './sortable-header'
import { TableSkeleton } from './skeleton/table-skeleton'
import { ErrorRetry } from './error-retry'
import { Pagination } from './pagination'
import { useContainerWidth } from '../../hooks/use-container-width'
import { getVisibleColumns, type ColumnConfig, type ColumnPriority } from '../../utils/column-visibility'
import type { SortDirection } from '../../hooks/use-sort'
import { useTranslation } from 'react-i18next'
import { theme } from 'antd'

export interface DataColumn<T> {
  key: string
  label: string
  priority: ColumnPriority
  sortField?: string
  cell: (row: T) => React.ReactNode
  cellStyle?: React.CSSProperties
}

interface Selection<T extends { id: number }> {
  selected: Set<number>
  allSelected: boolean
  toggleSelect: (id: number) => void
  toggleAll: () => void
  isSelectable?: (row: T) => boolean
}

interface DataTableProps<T extends { id: number }> {
  columns: DataColumn<T>[]
  data: { items: T[]; total: number | null; page: number; pages: number | null; hasMore?: boolean; truncated?: boolean } | null
  loading: boolean
  error?: string | null
  onRetry?: () => void
  onPage: (page: number) => void
  tableId: string
  empty: React.ReactNode
  summary?: React.ReactNode
  selection?: Selection<T>
  sort?: { field: string | null; direction: SortDirection; onToggle: (field: string) => void }
  minWidth?: number
  skeletonRows?: number
  limit?: number
  onLimit?: (limit: number) => void
}

const CHECKBOX = '__checkbox'

export function DataTable<T extends { id: number }>({
  columns, data, loading, error, onRetry, onPage, tableId, empty, summary, selection, sort, minWidth = 700, skeletonRows = 5, limit, onLimit,
}: DataTableProps<T>): React.ReactElement {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const [containerRef, containerWidth] = useContainerWidth()

  const configs: ColumnConfig[] = [
    ...(selection != null ? [{ key: CHECKBOX, priority: 1 as ColumnPriority }] : []),
    ...columns.map(c => ({ key: c.key, priority: c.priority })),
  ]
  const visible = getVisibleColumns(configs, containerWidth)
  const visibleColumns = columns.filter(c => visible.has(c.key))
  const showCheckbox = selection != null && visible.has(CHECKBOX)
  const hasSelectableRows = data?.items.some(row => selection?.isSelectable?.(row) ?? true) ?? false
  const thStyle: React.CSSProperties = {
    textAlign: 'left', padding: '6px 4px', fontSize: token.fontSize,
    color: token.colorTextSecondary, fontWeight: 500, whiteSpace: 'nowrap',
  }
  const tdStyle: React.CSSProperties = { padding: '6px 4px' }

  return (
    <div ref={containerRef}>
      {loading && <TableSkeleton rows={skeletonRows} columns={visibleColumns.length} hasCheckbox={selection != null} />}
      {error != null && <ErrorRetry error={error} onRetry={onRetry} />}

      {!loading && data != null && (
        <>
          {summary}

          {data.items.length === 0 ? (
            data.truncated === true || data.hasMore === true
              ? <p role="status" style={{ fontSize: token.fontSize, color: token.colorTextSecondary, padding: '12px 0' }}>{t('asset-pilot.common.none-on-page')}</p>
              : empty
          ) : (
            <ResponsiveTableWrapper stickyFirstColumn tableId={tableId} label={t('asset-pilot.common.table-scroll-region')}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: token.fontSize, minWidth }}>
                <thead>
                  <tr style={{ borderBottom: `2px solid ${token.colorBorderSecondary}` }}>
                    {showCheckbox && (
                      <th style={thStyle}><input type="checkbox" aria-label={t('asset-pilot.bulk.select-all')} checked={selection.allSelected} disabled={!hasSelectableRows} onChange={selection.toggleAll} /></th>
                    )}
                    {visibleColumns.map(col => (
                      col.sortField != null && sort != null
                        ? <SortableHeader key={col.key} label={col.label} field={col.sortField} currentField={sort.field} direction={sort.direction} onToggle={sort.onToggle} />
                        : <th key={col.key} style={thStyle}>{col.label}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.items.map(row => {
                    const isSelectable = selection?.isSelectable?.(row) ?? true

                    return (
                      <tr key={row.id} style={{ borderBottom: `1px solid ${token.colorBorderSecondary}`, background: selection?.selected.has(row.id) ? token.colorPrimaryBg : 'transparent' }}>
                        {showCheckbox && (
                          <td style={tdStyle}>
                            <input
                              type="checkbox"
                              aria-label={isSelectable
                                ? t('asset-pilot.bulk.select-row', { id: row.id })
                                : t('asset-pilot.bulk.row-not-selectable', { id: row.id })}
                              checked={isSelectable && selection.selected.has(row.id)}
                              disabled={!isSelectable}
                              onChange={() => selection.toggleSelect(row.id)}
                            />
                          </td>
                        )}
                        {visibleColumns.map(col => (
                          <td key={col.key} style={col.cellStyle != null ? { ...tdStyle, ...col.cellStyle } : tdStyle}>{col.cell(row)}</td>
                        ))}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </ResponsiveTableWrapper>
          )}

          <Pagination page={data.page} pages={data.pages} hasMore={data.hasMore} truncated={data.truncated} onPage={onPage} limit={limit} onLimit={onLimit} />
        </>
      )}
    </div>
  )
}
