import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useDuplicates, useMergeStrategies } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import type { DuplicateGroup, DuplicateFilters } from '../../types'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { GalleryGrid, ViewToggle, type ViewMode } from '../shared/gallery-grid'
import { OpenButton } from '../shared/open-button'
import { assetPilotApi } from '../../services/api'
import { formatBytes, truncate } from '../../utils/format'
import { MergeModal } from './merge-modal'
import { DuplicatesFilters } from './duplicates-filters'

export const DuplicatesTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(50)
  const [filters, setFilters] = useState<DuplicateFilters>({})
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const { data, loading, error, refetch } = useDuplicates(page, limit, filters)
  const strategies = useMergeStrategies()
  const [selected, setSelected] = useState<DuplicateGroup | null>(null)

  const onFilters = (next: DuplicateFilters): void => { setFilters(next); setPage(1) }

  const pages = data != null ? Math.max(1, Math.ceil(data.total / limit)) : 1

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.duplicates.title', { count: data?.total ?? 0 })}</h4>
        <button onClick={() => assetPilotApi.exportDuplicates(filters)} style={exportBtnStyle}>{t('asset-pilot.common.export-csv')}</button>
      </div>
      <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 16 }}>{t('asset-pilot.duplicates.scan-hint')}</p>

      <DuplicatesFilters filters={filters} onChange={onFilters} />

      <ViewToggle mode={viewMode} onChange={setViewMode} />

      {viewMode === 'gallery'
        ? (
          <GalleryGrid
            data={data}
            loading={loading}
            error={error != null ? t('asset-pilot.common.error', { message: error }) : null}
            empty={<EmptyState variant="no-data" title={t('asset-pilot.duplicates.empty')} description={t('asset-pilot.duplicates.empty-desc')} />}
            page={data?.page ?? 1}
            pages={pages}
            onPage={setPage}
            limit={limit}
            onLimit={n => { setLimit(n); setPage(1) }}
            pageSizeOptions={[20, 50, 100]}
            toCard={group => ({
              key: group.checksum,
              thumbnailId: group.representative?.id,
              type: group.representative?.type ?? 'unknown',
              fallbackLabel: group.representative?.filename.split('.').pop() ?? 'DUP',
              title: group.representative != null
                ? <OpenButton id={group.representative.id} type="asset" label={group.representative.filename} />
                : <span style={{ fontFamily: 'monospace', fontSize: 11 }}>{truncate(group.checksum, 16)}</span>,
              meta: <span style={{ fontSize: 11, color: '#8c8c8c' }}>{t('asset-pilot.columns.copies')}: {group.count} · {formatBytes(group.representative?.fileSize ?? group.fileSize)}</span>,
              actions: perms.admin ? <button onClick={() => setSelected(group)} style={actionBtnStyle}>{t('asset-pilot.duplicates.merge')}</button> : undefined,
            })}
          />
        )
        : error != null
        ? (
          <div>
            <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
            <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
          </div>
        )
        : loading
          ? <TableSkeleton rows={4} columns={5} />
          : data == null || data.items.length === 0
            ? <EmptyState variant="no-data" title={t('asset-pilot.duplicates.empty')} description={t('asset-pilot.duplicates.empty-desc')} />
            : (
            <>
              <ResponsiveTableWrapper>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 640 }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                      <th style={thStyle}>{t('asset-pilot.duplicates.checksum')}</th>
                      <th style={thStyle}>{t('asset-pilot.columns.size')}</th>
                      <th style={{ ...thStyle, textAlign: 'center' }}>{t('asset-pilot.columns.copies')}</th>
                      <th style={thStyle}>{t('asset-pilot.duplicates.example')}</th>
                      <th style={thStyle}>{t('asset-pilot.common.actions')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.items.map(group => (
                      <tr key={group.checksum} style={{ borderBottom: '1px solid #f5f5f5' }}>
                        <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 11 }}>{truncate(group.checksum, 16)}</td>
                        <td style={tdStyle}>{formatBytes(group.representative?.fileSize ?? group.fileSize)}</td>
                        <td style={{ ...tdStyle, textAlign: 'center' }}>{group.count}</td>
                        <td style={tdStyle}>
                          {group.representative != null
                            ? <OpenButton id={group.representative.id} type="asset" label={group.representative.filename} />
                            : <span style={{ color: '#bfbfbf' }}>-</span>}
                        </td>
                        <td style={tdStyle}>
                          {perms.admin
                            ? <button onClick={() => setSelected(group)} style={actionBtnStyle}>{t('asset-pilot.duplicates.merge')}</button>
                            : <span style={{ color: '#bfbfbf', fontSize: 12 }}>-</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </ResponsiveTableWrapper>

              <Pagination page={data.page} pages={pages} onPage={setPage} limit={limit} onLimit={n => { setLimit(n); setPage(1) }} pageSizeOptions={[20, 50, 100]} />
            </>
          )}

      {selected != null && (
        <MergeModal
          group={selected}
          strategies={strategies.data}
          canApply={perms.admin}
          onClose={() => setSelected(null)}
          onMerged={() => { setSelected(null); refetch() }}
        />
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 12, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13 }
const actionBtnStyle: React.CSSProperties = {
  padding: '3px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff',
  cursor: 'pointer', fontSize: 12, color: '#1677ff',
}
const exportBtnStyle: React.CSSProperties = {
  padding: '5px 12px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff',
  cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
