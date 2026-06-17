import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useUnusedAssets, useUnusedStats } from '../../hooks/use-asset-pilot-api'
import { assetPilotApi } from '../../services/api'
import type { UnusedAssetFilters } from '../../types'
import { UnusedAssetsFiltersBar } from './unused-assets-filters'
import { BulkActionBar } from './bulk-action-bar'
import { OpenButton } from '../shared/open-button'
import { TypeBadge } from '../shared/type-badge'
import { ConfidenceBadge } from '../shared/confidence-badge'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { SortableHeader } from '../shared/sortable-header'
import { useSort } from '../../hooks/use-sort'
import { useToast } from '../../hooks/use-toast'
import { useContainerWidth } from '../../hooks/use-container-width'
import { formatBytes, formatDate, pageNumbers } from '../../utils/format'
import { ExpandablePath } from '../shared/expandable-path'
import { getVisibleColumns, type ColumnConfig } from '../../utils/column-visibility'

const columns: ColumnConfig[] = [
  { key: 'checkbox', priority: 1 },
  { key: 'id', priority: 1 },
  { key: 'lock', priority: 1 },
  { key: 'filename', priority: 1 },
  { key: 'path', priority: 2 },
  { key: 'type', priority: 1 },
  { key: 'confidence', priority: 1 },
  { key: 'size', priority: 2 },
  { key: 'modified', priority: 3 },
]

export const UnusedAssetsTab: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { sortField, sortDirection, toggleSort, sortParams } = useSort()
  const [filters, setFilters] = useState<UnusedAssetFilters>({ page: 1, limit: 25 })
  const mergedFilters = { ...filters, ...sortParams }
  const { data, loading, error, refetch } = useUnusedAssets(mergedFilters)
  const { data: stats } = useUnusedStats()
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [actionLoading, setActionLoading] = useState(false)
  const [containerRef, containerWidth] = useContainerWidth()
  const visible = getVisibleColumns(columns, containerWidth)

  const allSelected = data != null && data.items.length > 0 && data.items.every(a => selected.has(a.id))

  const toggleSelect = (id: number): void => {
    setSelected(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const toggleAll = (): void => {
    if (data == null) return
    setSelected(allSelected ? new Set() : new Set(data.items.map(a => a.id)))
  }

  const handleBulkDelete = async (): Promise<void> => {
    if (selected.size === 0) return
    setActionLoading(true)
    try {
      const result = await assetPilotApi.bulkDeleteAssets([...selected])
      toast.success(t('asset-pilot.unused.deleted-result', { deleted: result.deleted ?? 0, failed: result.failed }))
      setSelected(new Set())
      refetch()
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Unknown error')
    } finally {
      setActionLoading(false)
    }
  }

  const handleBulkMove = async (targetFolder: string): Promise<void> => {
    if (selected.size === 0 || !targetFolder) return
    setActionLoading(true)
    try {
      const result = await assetPilotApi.bulkMoveAssets([...selected], targetFolder)
      toast.success(t('asset-pilot.unused.moved-result', { moved: result.moved ?? 0, failed: result.failed }))
      setSelected(new Set())
      refetch()
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Unknown error')
    } finally {
      setActionLoading(false)
    }
  }

  const hasFilters = filters.type != null || filters.extension != null || filters.before != null || filters.after != null || filters.folder != null || filters.confidence != null

  const goToPage = (page: number) => {
    setFilters(f => ({ ...f, page }))
    setSelected(new Set())
  }

  return (
    <div ref={containerRef}>
      {stats != null && (
        <div style={{ display: 'flex', gap: 16, marginBottom: 16, flexWrap: 'wrap' }}>
          <StatBox label={t('asset-pilot.unused.total-unused')} value={String(stats.totalCount)} />
          <StatBox label={t('asset-pilot.unused.total-size')} value={stats.totalSizeFormatted} />
          {stats.byType.map(bt => (
            <StatBox key={bt.type} label={bt.type} value={String(bt.count)} />
          ))}
        </div>
      )}

      <UnusedAssetsFiltersBar filters={filters} onChange={f => { setFilters(f); setSelected(new Set()) }} />

      {selected.size > 0 && (
        <BulkActionBar
          count={selected.size}
          loading={actionLoading}
          assetIds={[...selected]}
          onDelete={handleBulkDelete}
          onMove={handleBulkMove}
          onDeselect={() => setSelected(new Set())}
          onLockDone={() => { setSelected(new Set()); refetch() }}
        />
      )}

      {loading && <TableSkeleton rows={5} columns={7} hasCheckbox />}
      {error != null && <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>}

      {!loading && data != null && (
        <>
          <div style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>
            {t('asset-pilot.unused.showing', { count: data.items.length, total: data.total })} — {t('asset-pilot.common.page-info', { page: data.page, pages: data.pages })}
          </div>

          {data.items.length === 0 ? (
            <EmptyState
              variant={hasFilters ? 'no-results' : 'no-data'}
              title={hasFilters ? t('asset-pilot.empty.no-results-title') : t('asset-pilot.unused.no-results')}
              description={hasFilters ? t('asset-pilot.empty.no-results-desc') : undefined}
              action={hasFilters ? { label: t('asset-pilot.empty.clear-filters'), onClick: () => setFilters({ page: 1, limit: filters.limit }) } : undefined}
            />
          ) : (
            <ResponsiveTableWrapper stickyFirstColumn tableId="unused">
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, minWidth: 700 }}>
                <thead>
                  <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                    {visible.has('checkbox') && <th style={thStyle}><input type="checkbox" checked={allSelected} onChange={toggleAll} /></th>}
                    {visible.has('id') && <SortableHeader label={t('asset-pilot.columns.id')} field="id" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('lock') && <th style={thStyle}>{t('asset-pilot.columns.lock-status')}</th>}
                    {visible.has('filename') && <SortableHeader label={t('asset-pilot.columns.filename')} field="filename" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('path') && <th style={thStyle}>{t('asset-pilot.columns.path')}</th>}
                    {visible.has('type') && <SortableHeader label={t('asset-pilot.columns.type')} field="type" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('confidence') && <th style={thStyle}>{t('asset-pilot.confidence.label')}</th>}
                    {visible.has('size') && <SortableHeader label={t('asset-pilot.columns.size')} field="file_size" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('modified') && <SortableHeader label={t('asset-pilot.columns.modified')} field="modified_at" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                  </tr>
                </thead>
                <tbody>
                  {data.items.map(asset => (
                    <tr key={asset.id} style={{ borderBottom: '1px solid #f5f5f5', background: selected.has(asset.id) ? '#e6f4ff' : 'transparent' }}>
                      {visible.has('checkbox') && <td style={tdStyle}><input type="checkbox" checked={selected.has(asset.id)} onChange={() => toggleSelect(asset.id)} /></td>}
                      {visible.has('id') && <td style={tdStyle}><OpenButton id={asset.id} type="asset" /></td>}
                      {visible.has('lock') && <td style={tdStyle}>{asset.locked ? <LockBadge /> : null}</td>}
                      {visible.has('filename') && <td style={{ ...tdStyle, fontWeight: 500 }}><ExpandablePath path={asset.filename} maxLength={35} /></td>}
                      {visible.has('path') && <td style={tdStyle}><ExpandablePath path={asset.full_path} maxLength={50} /></td>}
                      {visible.has('type') && <td style={tdStyle}><TypeBadge type={asset.type} /></td>}
                      {visible.has('confidence') && <td style={tdStyle}><ConfidenceBadge confidence={asset.confidence} /></td>}
                      {visible.has('size') && <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>{formatBytes(asset.file_size)}</td>}
                      {visible.has('modified') && <td style={{ ...tdStyle, fontSize: 11, color: '#8c8c8c', whiteSpace: 'nowrap' }}>{formatDate(asset.modified_at, true)}</td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            </ResponsiveTableWrapper>
          )}

          {data.pages > 1 && (
            <div style={{ display: 'flex', justifyContent: 'center', gap: 4, marginTop: 16 }}>
              <button onClick={() => goToPage((filters.page ?? 1) - 1)} disabled={(filters.page ?? 1) <= 1} style={pageBtnStyle}>{t('asset-pilot.common.prev')}</button>
              {pageNumbers(data.page, data.pages).map(p => (
                <button key={p} onClick={() => goToPage(p)} style={{ ...pageBtnStyle, background: (filters.page ?? 1) === p ? '#1677ff' : '#fff', color: (filters.page ?? 1) === p ? '#fff' : '#595959' }}>{p}</button>
              ))}
              <button onClick={() => goToPage((filters.page ?? 1) + 1)} disabled={(filters.page ?? 1) >= data.pages} style={pageBtnStyle}>{t('asset-pilot.common.next')}</button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

const LockBadge: React.FC = () => (
  <span title="Locked" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '1px 6px', background: '#fff7e6', border: '1px solid #ffd591', borderRadius: 4, fontSize: 10, color: '#d46b08', fontWeight: 600 }}>
    🔒
  </span>
)

const StatBox: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div style={{ padding: '10px 16px', background: '#fafafa', border: '1px solid #f0f0f0', borderRadius: 8, minWidth: 80 }}>
    <div style={{ fontSize: 11, color: '#8c8c8c', marginBottom: 2 }}>{label}</div>
    <div style={{ fontSize: 16, fontWeight: 600, color: '#1a1a1a' }}>{value}</div>
  </div>
)

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px 4px', fontSize: 11, color: '#8c8c8c', fontWeight: 500, whiteSpace: 'nowrap' }
const tdStyle: React.CSSProperties = { padding: '6px 4px' }
const pageBtnStyle: React.CSSProperties = {
  padding: '4px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff',
  cursor: 'pointer', fontSize: 12,
}
