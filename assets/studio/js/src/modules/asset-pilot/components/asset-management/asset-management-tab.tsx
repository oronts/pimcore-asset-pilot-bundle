import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAssetSearch } from '../../hooks/use-asset-pilot-api'
import type { AssetItem, AssetSearchFilters } from '../../types'
import { BulkAssetActions } from './bulk-asset-actions'
import { AssetGallery } from './asset-gallery'
import { OpenButton } from '../shared/open-button'
import { TypeBadge } from '../shared/type-badge'
import { Highlight } from '../shared/highlight'
import { EmptyState } from '../shared/empty-state'
import { LockBadge } from '../shared/lock-badge'
import { DataTable, type DataColumn } from '../shared/data-table'
import { useSort } from '../../hooks/use-sort'
import { useToast } from '../../hooks/use-toast'
import { useRowSelection } from '../../hooks/use-row-selection'
import { useCart, CART_MAX } from '../../hooks/use-cart'
import { formatBytes, formatDate } from '../../utils/format'
import { ExpandablePath } from '../shared/expandable-path'
import { AssetCartBar } from './asset-cart-bar'

const typeOptions = ['', 'image', 'document', 'video', 'audio', 'text', 'archive']

export const AssetManagementTab: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { sortField, sortDirection, toggleSort, sortParams } = useSort()
  const [filters, setFilters] = useState<AssetSearchFilters>({ page: 1, limit: 50 })
  const [searchInput, setSearchInput] = useState('')
  const [objectIdInput, setObjectIdInput] = useState('')
  const mergedFilters = { ...filters, ...sortParams }
  const { data, loading, error, refetch } = useAssetSearch(mergedFilters)
  const { selected, allSelected, toggleSelect, toggleAll, clear } = useRowSelection(data?.items)
  const cart = useCart()
  const [viewMode, setViewMode] = useState<'list' | 'gallery'>('list')

  const addSelectionToCart = (): void => {
    const capped = cart.add([...selected])
    toast.success(t('asset-pilot.cart.added', { count: selected.size }))
    if (capped) toast.warning(t('asset-pilot.cart.full', { max: CART_MAX }))
    clear()
  }

  const handleSearch = (): void => {
    const objId = objectIdInput ? parseInt(objectIdInput, 10) : undefined
    setFilters(f => ({ ...f, q: searchInput || undefined, objectId: objId && objId > 0 ? objId : undefined, page: 1 }))
    clear()
  }

  const handleResult = (msg: string): void => {
    toast.success(msg)
    clear()
    refetch()
  }

  const goToPage = (page: number): void => {
    setFilters(f => ({ ...f, page }))
    clear()
  }

  const columns: DataColumn<AssetItem>[] = [
    { key: 'id', label: t('asset-pilot.columns.id'), priority: 1, sortField: 'id', cell: a => <OpenButton id={a.id} type="asset" /> },
    { key: 'lock', label: t('asset-pilot.columns.lock-status'), priority: 1, cell: a => (a.locked ? <LockBadge /> : null) },
    { key: 'filename', label: t('asset-pilot.columns.filename'), priority: 1, sortField: 'filename', cellStyle: { fontWeight: 500 }, cell: a => <ExpandablePath path={a.filename} maxLength={35} highlight={<Highlight text={a.filename ?? ''} query={filters.q} />} /> },
    { key: 'path', label: t('asset-pilot.columns.path'), priority: 2, cell: a => <ExpandablePath path={a.full_path} maxLength={50} highlight={<Highlight text={a.full_path ?? ''} query={filters.q} />} /> },
    { key: 'type', label: t('asset-pilot.columns.type'), priority: 1, sortField: 'type', cell: a => <TypeBadge type={a.type} /> },
    { key: 'size', label: t('asset-pilot.columns.size'), priority: 2, cellStyle: { whiteSpace: 'nowrap' }, cell: a => formatBytes(a.file_size) },
    { key: 'modified', label: t('asset-pilot.columns.modified'), priority: 3, sortField: 'modified_at', cellStyle: { fontSize: 11, color: '#8c8c8c', whiteSpace: 'nowrap' }, cell: a => formatDate(a.modified_at, true) },
  ]

  const summary = (
    <div style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>
      {t('asset-pilot.common.showing', { count: data?.items.length ?? 0, total: data?.total ?? 0 })} — {t('asset-pilot.common.page-info', { page: data?.page ?? 1, pages: data?.pages ?? 1 })}
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
            style={{ ...inputStyle, width: 220 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.object-id-label')}>
          <input
            type="number"
            value={objectIdInput}
            onChange={e => setObjectIdInput(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && handleSearch()}
            placeholder={t('asset-pilot.management.object-id-placeholder')}
            style={{ ...inputStyle, width: 120 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.type-filter')}>
          <select
            value={filters.type ?? ''}
            onChange={e => { setFilters(f => ({ ...f, type: e.target.value || undefined, page: 1 })); clear() }}
            style={selectStyle}
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
            style={{ ...inputStyle, width: 150 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.extension-filter')}>
          <input
            type="text"
            value={filters.extension ?? ''}
            onChange={e => { setFilters(f => ({ ...f, extension: e.target.value || undefined, page: 1 })); clear() }}
            placeholder={t('asset-pilot.management.extension-placeholder')}
            style={{ ...inputStyle, width: 90 }}
          />
        </FilterField>

        <FilterField label={t('asset-pilot.management.relation-filter')}>
          <select
            value={filters.referenced ?? ''}
            onChange={e => { setFilters(f => ({ ...f, referenced: (e.target.value || undefined) as AssetSearchFilters['referenced'], page: 1 })); clear() }}
            style={selectStyle}
          >
            <option value="">{t('asset-pilot.management.relation-all')}</option>
            <option value="referenced">{t('asset-pilot.management.relation-referenced')}</option>
            <option value="unreferenced">{t('asset-pilot.management.relation-unreferenced')}</option>
          </select>
        </FilterField>

        <button onClick={handleSearch} style={searchBtnStyle}>{t('asset-pilot.management.search-btn')}</button>
      </div>

      {cart.count > 0 && <AssetCartBar cart={cart} />}

      {selected.size > 0 && (
        <BulkAssetActions assetIds={[...selected]} onResult={handleResult} onDeselect={() => clear()} onAddToCart={addSelectionToCart} />
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
        <div style={{ display: 'inline-flex', border: '1px solid #d9d9d9', borderRadius: 6, overflow: 'hidden' }}>
          <button onClick={() => setViewMode('list')} style={viewMode === 'list' ? viewBtnActiveStyle : viewBtnStyle}>{t('asset-pilot.management.view-list')}</button>
          <button onClick={() => setViewMode('gallery')} style={viewMode === 'gallery' ? viewBtnActiveStyle : viewBtnStyle}>{t('asset-pilot.management.view-gallery')}</button>
        </div>
      </div>

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
            tableId="management"
            selection={{ selected, allSelected, toggleSelect, toggleAll }}
            sort={{ field: sortField, direction: sortDirection, onToggle: toggleSort }}
            summary={summary}
            empty={emptyState}
          />
        )
        : (
          <AssetGallery
            data={data}
            loading={loading}
            error={error != null ? t('asset-pilot.common.error', { message: error }) : null}
            onPage={goToPage}
            limit={filters.limit}
            onLimit={n => { setFilters(f => ({ ...f, limit: n, page: 1 })); clear() }}
            selection={{ selected, allSelected, toggleSelect, toggleAll }}
            summary={summary}
            empty={emptyState}
          />
        )}
    </div>
  )
}

const FilterField: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
    <label style={{ fontSize: 11, color: '#8c8c8c', fontWeight: 500 }}>{label}</label>
    {children}
  </div>
)

const inputStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const selectStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const searchBtnStyle: React.CSSProperties = { padding: '5px 16px', border: '1px solid #1677ff', borderRadius: 6, background: '#1677ff', color: '#fff', cursor: 'pointer', fontSize: 12, fontWeight: 500, alignSelf: 'flex-end' }
const viewBtnStyle: React.CSSProperties = { padding: '4px 12px', border: 'none', background: '#fff', color: '#595959', cursor: 'pointer', fontSize: 12 }
const viewBtnActiveStyle: React.CSSProperties = { ...viewBtnStyle, background: '#1677ff', color: '#fff', fontWeight: 500 }
