import React, { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAssetSearch } from '../../hooks/use-asset-pilot-api'
import type { AssetItem, AssetSearchFilters, MutationFeedback } from '../../types'
import { BulkAssetActions } from './bulk-asset-actions'
import { GalleryGrid, ViewToggle, type ViewMode } from '../shared/gallery-grid'
import { OpenButton } from '../shared/open-button'
import { TypeBadge } from '../shared/type-badge'
import { Highlight } from '../shared/highlight'
import { EmptyState } from '../shared/empty-state'
import { LockCell } from '../shared/lock-cell'
import { DataTable, type DataColumn } from '../shared/data-table'
import { useSort } from '../../hooks/use-sort'
import { useToast } from '../../hooks/use-toast'
import { useRowSelection } from '../../hooks/use-row-selection'
import { useCart, CART_MAX } from '../../hooks/use-cart'
import { formatBytes, formatDate } from '../../utils/format'
import { ExpandablePath } from '../shared/expandable-path'
import { AssetCartBar } from './asset-cart-bar'
import { theme } from 'antd'

const typeOptions = ['', 'image', 'document', 'video', 'audio', 'text', 'archive']

export const AssetManagementTab: React.FC = () => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const toast = useToast()
  const { sortField, sortDirection, toggleSort, sortParams } = useSort()
  const [filters, setFilters] = useState<AssetSearchFilters>({ page: 1, limit: 50 })
  const [searchInput, setSearchInput] = useState('')
  const [objectIdInput, setObjectIdInput] = useState('')
  const mergedFilters = { ...filters, ...sortParams }
  const { data, loading, error, refetch } = useAssetSearch(mergedFilters)
  // Any visible asset is selectable: safe actions (ZIP, cart, unlock) apply to protected assets too.
  // Protection is enforced per-action in BulkAssetActions, not by hiding rows from selection.
  const { selected, allSelected, toggleSelect, toggleAll, clear } = useRowSelection(data?.items)
  const lockedIds = useMemo(() => (data?.items ?? []).filter(a => a.locked).map(a => a.id), [data?.items])
  const cart = useCart()
  const [viewMode, setViewMode] = useState<ViewMode>('list')

  const addSelectionToCart = (): void => {
    const { added, capped } = cart.add([...selected])
    toast.success(t('asset-pilot.cart.added', { count: added }))
    if (capped) toast.warning(t('asset-pilot.cart.full', { max: CART_MAX }))
    clear()
  }

  const handleSearch = (): void => {
    const objId = objectIdInput ? parseInt(objectIdInput, 10) : undefined
    setFilters(f => ({ ...f, q: searchInput || undefined, objectId: objId && objId > 0 ? objId : undefined, page: 1 }))
    clear()
  }

  const handleResult = (result: MutationFeedback): void => {
    toast[result.severity](result.message)
    if (result.severity === 'error') return
    clear()
    refetch()
  }

  const goToPage = (page: number): void => {
    setFilters(f => ({ ...f, page }))
    clear()
  }

  const columns: DataColumn<AssetItem>[] = [
    { key: 'id', label: t('asset-pilot.columns.id'), priority: 1, sortField: 'id', cell: a => <OpenButton id={a.id} type="asset" /> },
    { key: 'lock', label: t('asset-pilot.columns.lock-status'), priority: 1, cell: a => (a.locked ? <LockCell id={a.id} onUnlocked={refetch} /> : null) },
    { key: 'filename', label: t('asset-pilot.columns.filename'), priority: 1, sortField: 'filename', cellStyle: { fontWeight: 500 }, cell: a => <ExpandablePath path={a.filename} maxLength={35} highlight={<Highlight text={a.filename ?? ''} query={filters.q} />} /> },
    { key: 'path', label: t('asset-pilot.columns.path'), priority: 2, cell: a => <ExpandablePath path={a.full_path} maxLength={50} highlight={<Highlight text={a.full_path ?? ''} query={filters.q} />} /> },
    { key: 'type', label: t('asset-pilot.columns.type'), priority: 1, sortField: 'type', cell: a => <TypeBadge type={a.type} /> },
    { key: 'size', label: t('asset-pilot.columns.size'), priority: 2, cellStyle: { whiteSpace: 'nowrap' }, cell: a => formatBytes(a.file_size) },
    { key: 'modified', label: t('asset-pilot.columns.modified'), priority: 3, sortField: 'modified_at', cellStyle: { color: token.colorTextSecondary, whiteSpace: 'nowrap' }, cell: a => formatDate(a.modified_at, true) },
  ]

  const summary = (
    <div style={{ fontSize: token.fontSize, color: token.colorTextSecondary, marginBottom: 8 }}>
      {data?.total != null
        ? `${t('asset-pilot.common.showing', { count: data.items.length, total: data.total })} — ${t('asset-pilot.common.page-info', { page: data.page, pages: data.pages ?? 1 })}`
        : t('asset-pilot.common.showing-page', { count: data?.items.length ?? 0 })}
    </div>
  )

  const emptyState = (
    <EmptyState
      variant={filters.q ? 'empty-search' : 'no-results'}
      title={filters.q ? t('asset-pilot.empty.empty-search-title') : t('asset-pilot.management.no-results')}
      description={filters.q ? t('asset-pilot.empty.empty-search-desc') : undefined}
    />
  )

  return (
    <div>
      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <FilterField label={t('asset-pilot.management.search-placeholder')}>
          <input
            type="text"
            value={searchInput}
            onChange={e => setSearchInput(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && handleSearch()}
            placeholder={t('asset-pilot.management.search-placeholder')}
            style={{ ...controlStyle(token), width: 220 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.object-id-label')}>
          <input
            type="number"
            value={objectIdInput}
            onChange={e => setObjectIdInput(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && handleSearch()}
            placeholder={t('asset-pilot.management.object-id-placeholder')}
            style={{ ...controlStyle(token), width: 120 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.type-filter')}>
          <select
            value={filters.type ?? ''}
            onChange={e => { setFilters(f => ({ ...f, type: e.target.value || undefined, page: 1 })); clear() }}
            style={controlStyle(token)}
          >
            {typeOptions.map(opt => <option key={opt} value={opt}>{opt || t('asset-pilot.management.all-types')}</option>)}
          </select>
        </FilterField>

        <FilterField label={t('asset-pilot.management.folder-filter')}>
          <input
            type="text"
            value={filters.folder ?? ''}
            onChange={e => { setFilters(f => ({ ...f, folder: e.target.value || undefined, page: 1 })); clear() }}
            placeholder={t('asset-pilot.management.folder-placeholder')}
            style={{ ...controlStyle(token), width: 150 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.extension-filter')}>
          <input
            type="text"
            value={filters.extension ?? ''}
            onChange={e => { setFilters(f => ({ ...f, extension: e.target.value || undefined, page: 1 })); clear() }}
            placeholder={t('asset-pilot.management.extension-placeholder')}
            style={{ ...controlStyle(token), width: 90 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.relation-filter')}>
          <select
            value={filters.referenced ?? ''}
            onChange={e => { setFilters(f => ({ ...f, referenced: (e.target.value || undefined) as AssetSearchFilters['referenced'], page: 1 })); clear() }}
            style={controlStyle(token)}
          >
            <option value="">{t('asset-pilot.management.relation-all')}</option>
            <option value="referenced">{t('asset-pilot.management.relation-referenced')}</option>
            <option value="unreferenced">{t('asset-pilot.management.relation-unreferenced')}</option>
          </select>
        </FilterField>

        <button onClick={handleSearch} style={{ ...controlStyle(token), borderColor: token.colorPrimary, background: token.colorPrimary, color: token.colorTextLightSolid, cursor: 'pointer', fontWeight: 500, alignSelf: 'flex-end' }}>{t('asset-pilot.management.search-btn')}</button>
      </div>

      {cart.count > 0 && <AssetCartBar cart={cart} />}

      {selected.size > 0 && (
        <BulkAssetActions assetIds={[...selected]} lockedIds={lockedIds} onResult={handleResult} onDeselect={() => clear()} onAddToCart={addSelectionToCart} />
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
            tableId="management"
            selection={{ selected, allSelected, toggleSelect, toggleAll }}
            sort={{ field: sortField, direction: sortDirection, onToggle: toggleSort }}
            summary={summary}
            empty={emptyState}
          />
        )
        : (
          <GalleryGrid
            data={data}
            loading={loading}
            error={error != null ? t('asset-pilot.common.error', { message: error }) : null}
            onRetry={refetch}
            empty={emptyState}
            summary={summary}
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
              meta: <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}><TypeBadge type={a.type} /><span style={{ fontSize: token.fontSize, color: token.colorTextSecondary }}>{formatBytes(a.file_size)}</span></div>,
              badges: a.locked ? <LockCell id={a.id} onUnlocked={refetch} /> : undefined,
            })}
          />
        )}
    </div>
  )
}

const FilterField: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <ThemedFilterField label={label}>{children}</ThemedFilterField>
)

const ThemedFilterField: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => {
  const { token } = theme.useToken()

  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
      <span style={{ fontSize: token.fontSize, color: token.colorTextSecondary, fontWeight: 500 }}>{label}</span>
      {children}
    </label>
  )
}

const controlStyle = (token: ReturnType<typeof theme.useToken>['token']): React.CSSProperties => ({
  padding: '5px 10px', border: `1px solid ${token.colorBorder}`, borderRadius: token.borderRadius,
  fontSize: token.fontSize, outline: 'none', background: token.colorBgContainer, color: token.colorText,
})
