import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useBrokenAssets } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import type { BrokenAssetFilters } from '../../types'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { OpenButton } from '../shared/open-button'
import { GalleryCards, ViewToggle, type ViewMode } from '../shared/gallery-grid'
import { HealModal } from './heal-modal'
import { HealHistory } from './heal-history'
import { IntegrityFilters } from './integrity-filters'
import { useRowSelection } from '../../hooks/use-row-selection'

export const IntegrityTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(25)
  const [filters, setFilters] = useState<BrokenAssetFilters>({})
  const { data, loading, error, refetch } = useBrokenAssets(page, limit, filters)
  const { selected, toggleSelect, clear } = useRowSelection(data?.items)
  const [healing, setHealing] = useState(false)
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const history = perms.admin ? <HealHistory key="heal-history" /> : null

  if (loading) return <div><TableSkeleton rows={4} columns={4} />{history}</div>
  if (error != null) return (
    <div>
      <div>
        <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
        <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
      </div>
      {history}
    </div>
  )

  if (data == null) return <div>{history}</div>

  const hasNext = data.hasNext
  const filtersActive = filters.folder != null || filters.type != null || filters.extension != null
  if (data.items.length === 0 && page === 1 && !hasNext && !filtersActive) {
    return (
      <div>
        <EmptyState variant="no-data" title={t('asset-pilot.integrity.empty')} description={t('asset-pilot.integrity.empty-desc')} />
        {history}
      </div>
    )
  }

  const goToPage = (nextPage: number): void => {
    setPage(nextPage)
    clear()
    setHealing(false)
  }

  const changeLimit = (nextLimit: number): void => {
    setLimit(nextLimit)
    setPage(1)
    clear()
    setHealing(false)
  }

  const changeFilters = (nextFilters: BrokenAssetFilters): void => {
    setFilters(nextFilters)
    setPage(1)
    clear()
    setHealing(false)
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.integrity.title', { count: data.broken })}</h4>
        {perms.operate && (
          <button onClick={() => setHealing(true)} disabled={selected.size === 0} style={healBtnStyle}>
            {t('asset-pilot.integrity.heal-selected', { count: selected.size })}
          </button>
        )}
      </div>

      <IntegrityFilters filters={filters} onChange={changeFilters} />
      <ViewToggle mode={viewMode} onChange={setViewMode} />

      {data.items.length === 0
        ? <p style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', padding: '12px 0' }}>{t(filtersActive && !hasNext ? 'asset-pilot.integrity.no-filter-results' : 'asset-pilot.common.none-on-page')}</p>
        : viewMode === 'gallery'
        ? (
          <GalleryCards
            cards={data.items.map(item => ({
              key: item.id,
              selectId: item.id,
              thumbnailId: item.id,
              type: 'image',
              fallbackLabel: item.path.split('.').pop() ?? t('asset-pilot.common.file'),
              title: <OpenButton id={item.id} type="asset" />,
              meta: <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-warning-text)' }}>{item.reason}</span>,
            }))}
            selection={{ selected, toggleSelect }}
          />
        )
        : (
          <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 680 }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                  <th style={thStyle}></th>
                  <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.path')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.reason')}</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map(item => (
                  <tr key={item.id} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)', background: selected.has(item.id) ? 'var(--ap-color-primary-bg)' : 'transparent' }}>
                    <td style={tdStyle}>
                      <input
                        type="checkbox"
                        checked={selected.has(item.id)}
                        aria-label={t('asset-pilot.integrity.select-asset', { id: item.id })}
                        onChange={() => toggleSelect(item.id)}
                      />
                    </td>
                    <td style={tdStyle}><OpenButton id={item.id} type="asset" /></td>
                    <td style={tdStyle}><ExpandablePath path={item.path} maxLength={48} /></td>
                    <td style={{ ...tdStyle, color: 'var(--ap-color-warning-text)' }}>{item.reason}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ResponsiveTableWrapper>
        )}

      <Pagination page={data.page} pages={hasNext ? page + 1 : page} onPage={goToPage} limit={limit} onLimit={changeLimit} pageSizeOptions={[20, 25, 50]} />

      {healing && (
        <HealModal
          ids={[...selected]}
          canApply={perms.operate}
          onClose={() => setHealing(false)}
          onHealed={() => { setHealing(false); clear(); refetch() }}
        />
      )}

      {history}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13 }
const healBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: 'var(--ap-color-success)', color: 'var(--ap-color-text-light-solid)', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
