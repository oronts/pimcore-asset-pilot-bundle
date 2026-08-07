import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useUnusedAssets, useUnusedStats } from '../../hooks/use-asset-pilot-api'
import { assetPilotApi } from '../../services/api'
import type { PlannedBulkActionResult, UnusedAsset, UnusedAssetFilters } from '../../types'
import { UnusedAssetsFiltersBar } from './unused-assets-filters'
import { BulkActionBar, type PlannedAction } from './bulk-action-bar'
import { OpenButton } from '../shared/open-button'
import { TypeBadge } from '../shared/type-badge'
import { ConfidenceBadge } from '../shared/confidence-badge'
import { EmptyState } from '../shared/empty-state'
import { LockCell } from '../shared/lock-cell'
import { DataTable, type DataColumn } from '../shared/data-table'
import { GalleryGrid, ViewToggle, type ViewMode } from '../shared/gallery-grid'
import { useSort } from '../../hooks/use-sort'
import { useToast } from '../../hooks/use-toast'
import { useRowSelection } from '../../hooks/use-row-selection'
import { formatBytes, formatDate } from '../../utils/format'
import { ExpandablePath } from '../shared/expandable-path'
import { theme } from 'antd'

export const UnusedAssetsTab: React.FC = () => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const toast = useToast()
  const { sortField, sortDirection, toggleSort, sortParams } = useSort()
  const [filters, setFilters] = useState<UnusedAssetFilters>({ page: 1, limit: 25 })
  const mergedFilters = { ...filters, ...sortParams }
  const { data, loading, error, refetch } = useUnusedAssets(mergedFilters)
  const { data: stats } = useUnusedStats()
  const { selected, allSelected, toggleSelect, toggleAll, clear } = useRowSelection(data?.items)
  const lockedIds = React.useMemo(() => (data?.items ?? []).filter(a => a.locked && selected.has(a.id)).map(a => a.id), [data?.items, selected])
  const [viewMode, setViewMode] = useState<ViewMode>('list')

  const handleBulkActionComplete = (action: PlannedAction, result: PlannedBulkActionResult): void => {
    if (action === 'delete') {
      const msg = t('asset-pilot.unused.deleted-result', { deleted: result.deleted ?? 0, failed: result.failed })
      const observerWarning = result.observerWarnings?.join(' ') ?? ''
      if (result.failed > 0 || observerWarning !== '') toast.warning([msg, observerWarning].filter(Boolean).join(' '))
      else toast.success(msg)
    } else if (action === 'move') {
      const msg = t('asset-pilot.unused.moved-result', { moved: result.moved ?? 0, failed: result.failed })
      const observerWarning = result.observerWarnings?.join(' ') ?? ''
      if (result.failed > 0 || observerWarning !== '') toast.warning([msg, observerWarning].filter(Boolean).join(' '))
      else toast.success(msg)
    } else {
      const msg = t('asset-pilot.unused.quarantined-result', { quarantined: result.quarantined ?? 0, failed: result.failed })
      const observerWarning = result.observerWarnings?.join(' ') ?? ''
      if (result.failed > 0 || observerWarning !== '') toast.warning([msg, observerWarning].filter(Boolean).join(' '))
      else toast.success(msg)
    }

    clear()
    refetch()
  }

  const hasFilters = filters.type != null || filters.extension != null || filters.before != null || filters.after != null || filters.folder != null || filters.confidence != null

  const goToPage = (page: number): void => {
    setFilters(f => ({ ...f, page }))
    clear()
  }

  const columns: DataColumn<UnusedAsset>[] = [
    { key: 'id', label: t('asset-pilot.columns.id'), priority: 1, sortField: 'id', cell: a => <OpenButton id={a.id} type="asset" /> },
    { key: 'lock', label: t('asset-pilot.columns.lock-status'), priority: 1, cell: a => (a.locked ? <LockCell id={a.id} onUnlocked={refetch} /> : null) },
    { key: 'filename', label: t('asset-pilot.columns.filename'), priority: 1, sortField: 'filename', cellStyle: { fontWeight: 500 }, cell: a => <ExpandablePath path={a.filename} maxLength={35} /> },
    { key: 'path', label: t('asset-pilot.columns.path'), priority: 2, cell: a => <ExpandablePath path={a.full_path} maxLength={50} /> },
    { key: 'type', label: t('asset-pilot.columns.type'), priority: 1, sortField: 'type', cell: a => <TypeBadge type={a.type} /> },
    { key: 'confidence', label: t('asset-pilot.confidence.label'), priority: 1, cell: a => <ConfidenceBadge confidence={a.confidence} /> },
    { key: 'size', label: t('asset-pilot.columns.size'), priority: 2, cellStyle: { whiteSpace: 'nowrap' }, cell: a => formatBytes(a.file_size) },
    { key: 'modified', label: t('asset-pilot.columns.modified'), priority: 3, sortField: 'modified_at', cellStyle: { color: token.colorTextSecondary, whiteSpace: 'nowrap' }, cell: a => formatDate(a.modified_at, true) },
  ]

  const unusedSummary = (
    <div style={{ fontSize: token.fontSize, color: token.colorTextSecondary, marginBottom: 8 }}>
      {data?.total != null
        ? `${t('asset-pilot.unused.showing', { count: data.items.length, total: data.total })} — ${t('asset-pilot.common.page-info', { page: data.page, pages: data.pages ?? 1 })}`
        : t('asset-pilot.common.showing-page', { count: data?.items.length ?? 0 })}
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
        <button onClick={() => assetPilotApi.exportUnused(filters)} style={{ padding: '5px 12px', border: `1px solid ${token.colorBorder}`, borderRadius: token.borderRadius, background: token.colorBgContainer, color: token.colorText, cursor: 'pointer', fontSize: token.fontSize, fontWeight: 500 }}>{t('asset-pilot.common.export-csv')}</button>
      </div>

      {stats != null && (
        <div style={{ display: 'flex', gap: 16, marginBottom: 16, flexWrap: 'wrap' }}>
          <StatBox label={t('asset-pilot.unused.total-unused')} value={String(stats.totalCount)} />
          <StatBox label={t('asset-pilot.unused.total-size')} value={stats.totalSizeFormatted} />
          {stats.unknownSizeCount > 0 && <StatBox label={t('asset-pilot.unused.unknown-size')} value={String(stats.unknownSizeCount)} />}
          {stats.byType.map(bt => (
            <StatBox key={bt.type} label={bt.type} value={String(bt.count)} />
          ))}
        </div>
      )}

      <UnusedAssetsFiltersBar filters={filters} onChange={f => { setFilters(f); clear() }} />

      {selected.size > 0 && (
        <BulkActionBar
          count={selected.size}
          assetIds={[...selected]}
          lockedIds={lockedIds}
          onActionComplete={handleBulkActionComplete}
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
            onRetry={refetch}
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
            onRetry={refetch}
            empty={unusedEmpty}
            summary={unusedSummary}
            page={data?.page ?? 1}
            pages={data?.pages ?? null}
            hasMore={data?.hasMore}
            truncated={data?.truncated}
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
              meta: <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}><TypeBadge type={a.type} /><ConfidenceBadge confidence={a.confidence} /><span style={{ fontSize: token.fontSize, color: token.colorTextSecondary }}>{formatBytes(a.file_size)}</span></div>,
              badges: a.locked ? <LockCell id={a.id} onUnlocked={refetch} /> : undefined,
            })}
          />
        )}
    </div>
  )
}

const StatBox: React.FC<{ label: string; value: string }> = ({ label, value }) => {
  const { token } = theme.useToken()

  return (
    <div style={{ padding: '10px 16px', background: token.colorFillAlter, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadiusLG, minWidth: 80 }}>
      <div style={{ fontSize: token.fontSize, color: token.colorTextSecondary, marginBottom: 2 }}>{label}</div>
      <div style={{ fontSize: token.fontSizeHeading4, fontWeight: 600, color: token.colorText }}>{value}</div>
    </div>
  )
}
