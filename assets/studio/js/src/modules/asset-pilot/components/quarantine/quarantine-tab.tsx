import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuarantine } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import { useToast } from '../../hooks/use-toast'
import { assetPilotApi } from '../../services/api'
import type { QuarantineItem, QuarantineFilters } from '../../types'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { OpenButton } from '../shared/open-button'
import { GalleryGrid, ViewToggle, type ViewMode } from '../shared/gallery-grid'
import { formatDate } from '../../utils/format'
import { QuarantineFiltersBar } from './quarantine-filters'

export const QuarantineTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(50)
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const [filters, setFilters] = useState<QuarantineFilters>({})
  const { data, loading, error, refetch } = useQuarantine(page, limit, filters)
  const [restoring, setRestoring] = useState<QuarantineItem | null>(null)
  const [busy, setBusy] = useState(false)

  const onFilters = (next: QuarantineFilters): void => { setFilters(next); setPage(1) }

  const handleRestore = async (): Promise<void> => {
    if (restoring == null) return
    setBusy(true)
    try {
      await assetPilotApi.restoreQuarantine(restoring.asset_id)
      toast.success(t('asset-pilot.quarantine.restored', { id: restoring.asset_id }))
      setRestoring(null)
      refetch()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.quarantine.restore-failed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.quarantine.title', { count: data?.total ?? data?.items.length ?? 0 })}</h4>
        <button onClick={() => assetPilotApi.exportQuarantine(filters)} style={exportBtnStyle}>{t('asset-pilot.common.export-csv')}</button>
      </div>

      <QuarantineFiltersBar filters={filters} onChange={onFilters} />

      <ViewToggle mode={viewMode} onChange={setViewMode} />

      {viewMode === 'gallery'
        ? (
          <GalleryGrid
            data={data}
            loading={loading}
            error={error != null ? t('asset-pilot.common.error', { message: error }) : null}
            onRetry={refetch}
            empty={<EmptyState variant="no-data" title={t('asset-pilot.quarantine.empty')} description={t('asset-pilot.quarantine.empty-desc')} />}
            page={data?.page ?? 1}
            pages={data?.pages ?? null}
            hasMore={data?.hasMore}
            onPage={setPage}
            limit={limit}
            onLimit={n => { setLimit(n); setPage(1) }}
            toCard={item => ({
              key: item.asset_id,
              thumbnailId: item.asset_id,
              type: item.type,
              fallbackLabel: item.filename.split('.').pop() ?? item.type,
              title: <OpenButton id={item.asset_id} type="asset" label={item.filename} />,
              meta: <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{formatDate(item.quarantined_at, true)}</span>,
              actions: perms.operate ? <button onClick={() => setRestoring(item)} style={actionBtnStyle}>{t('asset-pilot.quarantine.restore')}</button> : undefined,
            })}
          />
        )
        : error != null
        ? (
          <div>
            <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
            <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
          </div>
        )
        : loading
          ? <TableSkeleton rows={4} columns={4} />
          : data == null || data.items.length === 0
            ? <EmptyState variant="no-data" title={t('asset-pilot.quarantine.empty')} description={t('asset-pilot.quarantine.empty-desc')} />
            : (
            <>
              <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 720 }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                      <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                      <th style={thStyle}>{t('asset-pilot.quarantine.original-path')}</th>
                      <th style={thStyle}>{t('asset-pilot.quarantine.quarantined-at')}</th>
                      <th style={thStyle}>{t('asset-pilot.common.actions')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.items.map(item => (
                      <tr key={item.asset_id} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                        <td style={tdStyle}><OpenButton id={item.asset_id} type="asset" label={item.filename} /></td>
                        <td style={tdStyle}><ExpandablePath path={item.original_path} maxLength={48} /></td>
                        <td style={tdStyle}>{formatDate(item.quarantined_at, true)}</td>
                        <td style={tdStyle}>
                          {perms.operate
                            ? <button onClick={() => setRestoring(item)} style={actionBtnStyle}>{t('asset-pilot.quarantine.restore')}</button>
                            : <span style={{ color: 'var(--ap-color-text-tertiary)', fontSize: 'var(--ap-font-size)' }}>-</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </ResponsiveTableWrapper>

              <Pagination page={data.page} pages={data.pages} hasMore={data.hasMore} truncated={data.truncated} onPage={setPage} limit={limit} onLimit={n => { setLimit(n); setPage(1) }} />
            </>
          )}

      {restoring != null && (
        <ConfirmDialog
          variant="warning"
          title={t('asset-pilot.quarantine.restore-title')}
          description={t('asset-pilot.quarantine.restore-desc', { path: restoring.original_path })}
          confirmLabel={t('asset-pilot.quarantine.restore')}
          loading={busy}
          onConfirm={() => { void handleRestore() }}
          onCancel={() => setRestoring(null)}
        />
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13 }
const actionBtnStyle: React.CSSProperties = {
  padding: '3px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-primary)',
}
const exportBtnStyle: React.CSSProperties = {
  padding: '5px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
