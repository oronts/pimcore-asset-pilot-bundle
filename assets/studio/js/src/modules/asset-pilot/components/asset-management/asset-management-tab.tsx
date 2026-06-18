import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAssetSearch } from '../../hooks/use-asset-pilot-api'
import type { AssetSearchFilters } from '../../types'
import { BulkAssetActions } from './bulk-asset-actions'
import { OpenButton } from '../shared/open-button'
import { TypeBadge } from '../shared/type-badge'
import { Highlight } from '../shared/highlight'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { SortableHeader } from '../shared/sortable-header'
import { useSort } from '../../hooks/use-sort'
import { useToast } from '../../hooks/use-toast'
import { useRowSelection } from '../../hooks/use-row-selection'
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
  { key: 'size', priority: 2 },
  { key: 'modified', priority: 3 },
]

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
  const [containerRef, containerWidth] = useContainerWidth()
  const visible = getVisibleColumns(columns, containerWidth)

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

  return (
    <div ref={containerRef}>
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

        <button onClick={handleSearch} style={searchBtnStyle}>{t('asset-pilot.management.search-btn')}</button>
      </div>

      {selected.size > 0 && (
        <BulkAssetActions assetIds={[...selected]} onResult={handleResult} onDeselect={() => clear()} />
      )}

      {loading && <TableSkeleton rows={5} columns={7} hasCheckbox />}
      {error != null && <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>}

      {!loading && data != null && (
        <>
          <div style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>
            {t('asset-pilot.common.showing', { count: data.items.length, total: data.total })} — {t('asset-pilot.common.page-info', { page: data.page, pages: data.pages })}
          </div>

          {data.items.length === 0 ? (
            <EmptyState
              variant={filters.q ? 'empty-search' : 'no-results'}
              title={filters.q ? t('asset-pilot.empty.empty-search-title') : t('asset-pilot.management.no-results')}
              description={filters.q ? t('asset-pilot.empty.empty-search-desc') : undefined}
            />
          ) : (
            <ResponsiveTableWrapper stickyFirstColumn tableId="management">
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, minWidth: 700 }}>
                <thead>
                  <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                    {visible.has('checkbox') && <th style={thStyle}><input type="checkbox" checked={allSelected} onChange={toggleAll} /></th>}
                    {visible.has('id') && <SortableHeader label={t('asset-pilot.columns.id')} field="id" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('lock') && <th style={thStyle}>{t('asset-pilot.columns.lock-status')}</th>}
                    {visible.has('filename') && <SortableHeader label={t('asset-pilot.columns.filename')} field="filename" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('path') && <th style={thStyle}>{t('asset-pilot.columns.path')}</th>}
                    {visible.has('type') && <SortableHeader label={t('asset-pilot.columns.type')} field="type" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                    {visible.has('size') && <th style={thStyle}>{t('asset-pilot.columns.size')}</th>}
                    {visible.has('modified') && <SortableHeader label={t('asset-pilot.columns.modified')} field="modified_at" currentField={sortField} direction={sortDirection} onToggle={toggleSort} />}
                  </tr>
                </thead>
                <tbody>
                  {data.items.map(asset => (
                    <tr key={asset.id} style={{ borderBottom: '1px solid #f5f5f5', background: selected.has(asset.id) ? '#e6f4ff' : 'transparent' }}>
                      {visible.has('checkbox') && <td style={tdStyle}><input type="checkbox" checked={selected.has(asset.id)} onChange={() => toggleSelect(asset.id)} /></td>}
                      {visible.has('id') && <td style={tdStyle}><OpenButton id={asset.id} type="asset" /></td>}
                      {visible.has('lock') && <td style={tdStyle}>{asset.locked ? <LockBadge /> : null}</td>}
                      {visible.has('filename') && <td style={{ ...tdStyle, fontWeight: 500 }}><ExpandablePath path={asset.filename} maxLength={35} highlight={<Highlight text={asset.filename ?? ''} query={filters.q} />} /></td>}
                      {visible.has('path') && <td style={tdStyle}><ExpandablePath path={asset.full_path} maxLength={50} highlight={<Highlight text={asset.full_path ?? ''} query={filters.q} />} /></td>}
                      {visible.has('type') && <td style={tdStyle}><TypeBadge type={asset.type} /></td>}
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

const FilterField: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
    <label style={{ fontSize: 11, color: '#8c8c8c', fontWeight: 500 }}>{label}</label>
    {children}
  </div>
)

const LockBadge: React.FC = () => (
  <span title="Locked" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '1px 6px', background: '#fff7e6', border: '1px solid #ffd591', borderRadius: 4, fontSize: 10, color: '#d46b08', fontWeight: 600 }}>
    🔒
  </span>
)

const inputStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const selectStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const searchBtnStyle: React.CSSProperties = { padding: '5px 16px', border: '1px solid #1677ff', borderRadius: 6, background: '#1677ff', color: '#fff', cursor: 'pointer', fontSize: 12, fontWeight: 500, alignSelf: 'flex-end' }
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px 4px', fontSize: 11, color: '#8c8c8c', fontWeight: 500, whiteSpace: 'nowrap' }
const tdStyle: React.CSSProperties = { padding: '6px 4px' }
const pageBtnStyle: React.CSSProperties = { padding: '4px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', cursor: 'pointer', fontSize: 12 }
