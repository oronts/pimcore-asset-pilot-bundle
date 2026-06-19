import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useDuplicates, useMergeStrategies } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import type { DuplicateGroup } from '../../types'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { formatBytes, truncate } from '../../utils/format'
import { MergeModal } from './merge-modal'

const LIMIT = 50

export const DuplicatesTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const [page, setPage] = useState(1)
  const { data, loading, error, refetch } = useDuplicates(page, LIMIT)
  const strategies = useMergeStrategies()
  const [selected, setSelected] = useState<DuplicateGroup | null>(null)

  if (loading) return <TableSkeleton rows={4} columns={4} />
  if (error != null) return (
    <div>
      <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  if (data == null || data.items.length === 0) {
    return (
      <div>
        <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 16 }}>{t('asset-pilot.duplicates.scan-hint')}</p>
        <EmptyState variant="no-data" title={t('asset-pilot.duplicates.empty')} description={t('asset-pilot.duplicates.empty-desc')} />
      </div>
    )
  }

  const pages = Math.max(1, Math.ceil(data.total / LIMIT))

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.duplicates.title', { count: data.total })}</h4>
      </div>
      <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 16 }}>{t('asset-pilot.duplicates.scan-hint')}</p>

      <ResponsiveTableWrapper>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 640 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
              <th style={thStyle}>{t('asset-pilot.duplicates.checksum')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.size')}</th>
              <th style={{ ...thStyle, textAlign: 'center' }}>{t('asset-pilot.columns.copies')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
              <th style={thStyle}>{t('asset-pilot.common.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map(group => (
              <tr key={group.checksum} style={{ borderBottom: '1px solid #f5f5f5' }}>
                <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 11 }}>{truncate(group.checksum, 16)}</td>
                <td style={tdStyle}>{formatBytes(group.fileSize)}</td>
                <td style={{ ...tdStyle, textAlign: 'center' }}>{group.count}</td>
                <td style={tdStyle}><ExpandablePath path={group.assetIds.map(id => `#${id}`).join(', ')} maxLength={40} /></td>
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

      <Pagination page={data.page} pages={pages} onPage={setPage} />

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
