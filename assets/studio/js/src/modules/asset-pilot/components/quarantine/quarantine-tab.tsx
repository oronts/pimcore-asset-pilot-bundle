import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuarantine } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import { useToast } from '../../hooks/use-toast'
import { assetPilotApi } from '../../services/api'
import type { QuarantineItem } from '../../types'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { formatDate } from '../../utils/format'

const LIMIT = 50

export const QuarantineTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const { data, loading, error, refetch } = useQuarantine(page, LIMIT)
  const [restoring, setRestoring] = useState<QuarantineItem | null>(null)
  const [busy, setBusy] = useState(false)

  if (loading) return <TableSkeleton rows={4} columns={4} />
  if (error != null) return (
    <div>
      <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  if (data == null || data.items.length === 0) {
    return <EmptyState variant="no-data" title={t('asset-pilot.quarantine.empty')} description={t('asset-pilot.quarantine.empty-desc')} />
  }

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
      <h4 style={{ margin: '0 0 16px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.quarantine.title', { count: data.total })}</h4>

      <ResponsiveTableWrapper>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 720 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
              <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
              <th style={thStyle}>{t('asset-pilot.quarantine.original-path')}</th>
              <th style={thStyle}>{t('asset-pilot.quarantine.quarantined-at')}</th>
              <th style={thStyle}>{t('asset-pilot.common.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map(item => (
              <tr key={item.asset_id} style={{ borderBottom: '1px solid #f5f5f5' }}>
                <td style={tdStyle}>#{item.asset_id}</td>
                <td style={tdStyle}><ExpandablePath path={item.original_path} maxLength={48} /></td>
                <td style={tdStyle}>{formatDate(item.quarantined_at, true)}</td>
                <td style={tdStyle}>
                  {perms.operate
                    ? <button onClick={() => setRestoring(item)} style={actionBtnStyle}>{t('asset-pilot.quarantine.restore')}</button>
                    : <span style={{ color: '#bfbfbf', fontSize: 12 }}>-</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </ResponsiveTableWrapper>

      <Pagination page={data.page} pages={data.pages} onPage={setPage} />

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

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 12, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13 }
const actionBtnStyle: React.CSSProperties = {
  padding: '3px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff',
  cursor: 'pointer', fontSize: 12, color: '#1677ff',
}
