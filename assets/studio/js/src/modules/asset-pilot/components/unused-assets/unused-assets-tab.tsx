import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useUnusedAssets, useUnusedStats } from '../../hooks/use-asset-pilot-api'
import { assetPilotApi } from '../../services/api'
import type { UnusedAsset, UnusedAssetFilters } from '../../types'
import { UnusedAssetsFiltersBar } from './unused-assets-filters'
import { BulkActionBar } from './bulk-action-bar'
import { OpenButton } from '../shared/open-button'
import { TypeBadge } from '../shared/type-badge'
import { ConfidenceBadge } from '../shared/confidence-badge'
import { EmptyState } from '../shared/empty-state'
import { LockBadge } from '../shared/lock-badge'
import { DataTable, type DataColumn } from '../shared/data-table'
import { GalleryGrid, ViewToggle, type ViewMode } from '../shared/gallery-grid'
import { useSort } from '../../hooks/use-sort'
import { useToast } from '../../hooks/use-toast'
import { useRowSelection } from '../../hooks/use-row-selection'
import { formatBytes, formatDate } from '../../utils/format'
import { ExpandablePath } from '../shared/expandable-path'

export const UnusedAssetsTab: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { sortField, sortDirection, toggleSort, sortParams } = useSort()
  const [filters, setFilters] = useState<UnusedAssetFilters>({ page: 1, limit: 25 })
  const mergedFilters = { ...filters, ...sortParams }
  const { data, loading, error, refetch } = useUnusedAssets(mergedFilters)
  const { data: stats } = useUnusedStats()
  const { selected, allSelected, toggleSelect, toggleAll, clear } = useRowSelection(data?.items)
  const [actionLoading, setActionLoading] = useState(false)
  const [viewMode, setViewMode] = useState<ViewMode>('list')

  const handleBulkDelete = async (): Promise<void> => {
    if (selected.size === 0) return
    setActionLoading(true)
    try {
      const result = await assetPilotApi.bulkDeleteAssets([...selected])
      const msg = t('asset-pilot.unused.deleted-result', { deleted: result.deleted ?? 0, failed: result.failed })
      if (result.failed > 0) toast.warning(msg)
      else toast.success(msg)
      clear()
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
      const msg = t('asset-pilot.unused.moved-result', { moved: result.moved ?? 0, failed: result.failed })
      if (result.failed > 0) toast.warning(msg)
      else toast.success(msg)
      clear()
      refetch()
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Unknown error')
    } finally {
      setActionLoading(false)
    }
  }

  const hasFilters = filters.type != null || filters.extension != null || filters.before != null || filters.after != null || filters.folder != null || filters.confidence != null

  const goToPage = (page: number): void => {
    setFilters(f => ({ ...f, page }))
    clear()
  }

  const columns: DataColumn<UnusedAsset>[] = [
    { key: 'id', label: t('asset-pilot.columns.id'), priority: 1, sortField: 'id', cell: a => <OpenButton id={a.id} type="asset" /> },
    { key: 'lock', label: t('asset-pilot.columns.lock-status'), priority: 1, cell: a => (a.locked ? <LockBadge /> : null) },
    { key: 'filename', label: t('asset-pilot.columns.filename'), priority: 1, sortField: 'filename', cellStyle: { fontWeight: 500 }, cell: a => <ExpandablePath path={a.filename} maxLength={35} /> },
    { key: 'path', label: t('asset-pilot.columns.path'), priority: 2, cell: a => <ExpandablePath path={a.full_path} maxLength={50} /> },
    { key: 'type', label: t('asset-pilot.columns.type'), priority: 1, sortField: 'type', cell: a => <TypeBadge type={a.type} /> },
    { key: 'confidence', label: t('asset-pilot.confidence.label'), priority: 1, cell: a => <ConfidenceBadge confidence={a.confidence} /> },
    { key: 'size', label: t('asset-pilot.columns.size'), priority: 2, cellStyle: { whiteSpace: 'nowrap' }, cell: a => formatBytes(a.file_size) },
    { key: 'modified', label: t('asset-pilot.columns.modified'), priority: 3, sortField: 'modified_at', cellStyle: { fontSize: 11, color: '#8c8c8c', whiteSpace: 'nowrap' }, cell: a => formatDate(a.modified_at, true) },
  ]

  const unusedSummary = (
    <div style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>
      {t('asset-pilot.unused.showing', { count: data?.items.length ?? 0, total: data?.total ?? 0 })} — {t('asset-pilot.common.page-info', { page: data?.page ?? 1, pages: data?.pages ?? 1 })}
    </div>
  )

  const unusedEmpty = (
    <EmptyState
      variant={hasFilters ? 'no-results' : 'no-data'}
      title={hasFilters ? t('asset-pilot.empty.no-results-title') : t('asset-pilot.unused.no-results')}
      description={hasFilters ? t('asset-pilot.empty.no-results-desc') : undefined}
      action={hasFilters ? { label: t('asset-pilot.empty.clear-filters'), onClick: () => setFilters({ page: 1, limit: filters.limit }) } : undefined}
    />
  )

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
        <button onClick={() => assetPilotApi.exportUnused(filters)} style={exportBtnStyle}>{t('asset-pilot.common.export-csv')}</button>
      </div>

      {stats != null && (
        <div style={{ display: 'flex', gap: 16, marginBottom: 16, flexWrap: 'wrap' }}>
          <StatBox label={t('asset-pilot.unused.total-unused')} value={String(stats.totalCount)} />
          <StatBox label={t('asset-pilot.unused.total-size')} value={stats.totalSizeFormatted} />
          {stats.byType.map(bt => (
            <StatBox key={bt.type} label={bt.type} value={String(bt.count)} />
          ))}
        </div>
      )}

      <UnusedAssetsFiltersBar filters={filters} onChange={f => { setFilters(f); clear() }} />

      {selected.size > 0 && (
        <BulkActionBar
          count={selected.size}
          loading={actionLoading}
          assetIds={[...selected]}
          onDelete={handleBulkDelete}
          onMove={handleBulkMove}
          onDeselect={() => clear()}
          onLockDone={() => { clear(); refetch() }}
        />
      )}

      <ViewToggle mode={viewMode} onChange={setViewMode} />

      {viewMode === 'list'
        ? (
          <DataTable
            columns={columns}
            data={data}
            loading={loading}
            error={error != null ? t('asset-pilot.common.error', { message: error }) : null}
            onPage={goToPage}
            limit={filters.limit}
            onLimit={n => { setFilters(f => ({ ...f, limit: n, page: 1 })); clear() }}
            tableId="unused"
            selection={{ selected, allSelected, toggleSelect, toggleAll }}
            sort={{ field: sortField, direction: sortDirection, onToggle: toggleSort }}
            summary={unusedSummary}
            empty={unusedEmpty}
          />
        )
        : (
          <GalleryGrid
            data={data}
            loading={loading}
            error={error != null ? t('asset-pilot.common.error', { message: error }) : null}
            empty={unusedEmpty}
            summary={unusedSummary}
            page={data?.page ?? 1}
            pages={data?.pages ?? 1}
            onPage={goToPage}
            limit={filters.limit}
            onLimit={n => { setFilters(f => ({ ...f, limit: n, page: 1 })); clear() }}
            selection={{ selected, allSelected, toggleSelect, toggleAll }}
            toCard={a => ({
              key: a.id,
              selectId: a.id,
              thumbnailId: a.id,
              type: a.type,
              fallbackLabel: a.filename.split('.').pop() ?? a.type,
              title: <OpenButton id={a.id} type="asset" label={a.filename} />,
              meta: <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}><TypeBadge type={a.type} /><ConfidenceBadge confidence={a.confidence} /><span style={{ fontSize: 11, color: '#8c8c8c' }}>{formatBytes(a.file_size)}</span></div>,
              badges: a.locked ? <LockBadge /> : undefined,
            })}
          />
        )}
    </div>
  )
}

const exportBtnStyle: React.CSSProperties = {
  padding: '5px 12px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff',
  cursor: 'pointer', fontSize: 12, fontWeight: 500,
}

const StatBox: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div style={{ padding: '10px 16px', background: '#fafafa', border: '1px solid #f0f0f0', borderRadius: 8, minWidth: 80 }}>
    <div style={{ fontSize: 11, color: '#8c8c8c', marginBottom: 2 }}>{label}</div>
    <div style={{ fontSize: 16, fontWeight: 600, color: '#1a1a1a' }}>{value}</div>
  </div>
)
