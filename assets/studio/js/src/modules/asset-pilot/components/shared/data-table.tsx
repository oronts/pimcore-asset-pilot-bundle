import React from 'react'
import { ResponsiveTableWrapper } from './responsive-table-wrapper'
import { SortableHeader } from './sortable-header'
import { TableSkeleton } from './skeleton/table-skeleton'
import { Pagination } from './pagination'
import { useContainerWidth } from '../../hooks/use-container-width'
import { getVisibleColumns, type ColumnConfig, type ColumnPriority } from '../../utils/column-visibility'
import type { SortDirection } from '../../hooks/use-sort'

export interface DataColumn<T> {
  key: string
  label: string
  priority: ColumnPriority
  sortField?: string
  cell: (row: T) => React.ReactNode
  cellStyle?: React.CSSProperties
}

interface Selection {
  selected: Set<number>
  allSelected: boolean
  toggleSelect: (id: number) => void
  toggleAll: () => void
}

interface DataTableProps<T extends { id: number }> {
  columns: DataColumn<T>[]
  data: { items: T[]; total: number; page: number; pages: number } | null
  loading: boolean
  error?: string | null
  onPage: (page: number) => void
  tableId: string
  empty: React.ReactNode
  summary?: React.ReactNode
  selection?: Selection
  sort?: { field: string | null; direction: SortDirection; onToggle: (field: string) => void }
  minWidth?: number
  skeletonRows?: number
  limit?: number
  onLimit?: (limit: number) => void
}

const CHECKBOX = '__checkbox'

export function DataTable<T extends { id: number }>({
  columns, data, loading, error, onPage, tableId, empty, summary, selection, sort, minWidth = 700, skeletonRows = 5, limit, onLimit,
}: DataTableProps<T>): React.ReactElement {
  const [containerRef, containerWidth] = useContainerWidth()

  const configs: ColumnConfig[] = [
    ...(selection != null ? [{ key: CHECKBOX, priority: 1 as ColumnPriority }] : []),
    ...columns.map(c => ({ key: c.key, priority: c.priority })),
  ]
  const visible = getVisibleColumns(configs, containerWidth)
  const visibleColumns = columns.filter(c => visible.has(c.key))
  const showCheckbox = selection != null && visible.has(CHECKBOX)

  return (
    <div ref={containerRef}>
      {loading && <TableSkeleton rows={skeletonRows} columns={visibleColumns.length} hasCheckbox={selection != null} />}
      {error != null && <p style={{ color: '#ff4d4f', fontSize: 13 }}>{error}</p>}

      {!loading && data != null && (
        <>
          {summary}

          {data.items.length === 0 ? empty : (
            <ResponsiveTableWrapper stickyFirstColumn tableId={tableId}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, minWidth }}>
                <thead>
                  <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                    {showCheckbox && (
                      <th style={thStyle}><input type="checkbox" checked={selection.allSelected} onChange={selection.toggleAll} /></th>
                    )}
                    {visibleColumns.map(col => (
                      col.sortField != null && sort != null
                        ? <SortableHeader key={col.key} label={col.label} field={col.sortField} currentField={sort.field} direction={sort.direction} onToggle={sort.onToggle} />
                        : <th key={col.key} style={thStyle}>{col.label}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.items.map(row => (
                    <tr key={row.id} style={{ borderBottom: '1px solid #f5f5f5', background: selection?.selected.has(row.id) ? '#e6f4ff' : 'transparent' }}>
                      {showCheckbox && (
                        <td style={tdStyle}><input type="checkbox" checked={selection.selected.has(row.id)} onChange={() => selection.toggleSelect(row.id)} /></td>
                      )}
                      {visibleColumns.map(col => (
                        <td key={col.key} style={col.cellStyle != null ? { ...tdStyle, ...col.cellStyle } : tdStyle}>{col.cell(row)}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </ResponsiveTableWrapper>
          )}

          <Pagination page={data.page} pages={data.pages} onPage={onPage} limit={limit} onLimit={onLimit} />
        </>
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px 4px', fontSize: 11, color: '#8c8c8c', fontWeight: 500, whiteSpace: 'nowrap' }
const tdStyle: React.CSSProperties = { padding: '6px 4px' }
