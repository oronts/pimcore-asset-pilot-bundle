import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useEmptyFolders } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import { useToast } from '../../hooks/use-toast'
import { assetPilotApi } from '../../services/api'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { ConfirmDialog } from '../shared/confirm-dialog'

const LIMIT = 50

export const EmptyFoldersTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const { data, loading, error, refetch } = useEmptyFolders(page, LIMIT)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)

  if (loading) return <TableSkeleton rows={4} columns={2} />
  if (error != null) return (
    <div>
      <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  if (data == null || data.items.length === 0) {
    return <EmptyState variant="no-data" title={t('asset-pilot.folders.empty')} description={t('asset-pilot.folders.empty-desc')} />
  }

  const toggle = (id: number): void => {
    setSelected(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const handleDelete = async (): Promise<void> => {
    setBusy(true)
    try {
      const result = await assetPilotApi.deleteEmptyFolders([...selected])
      toast.success(t('asset-pilot.folders.deleted', { count: result.deleted }))
      if (result.failed > 0) toast.warning(t('asset-pilot.folders.delete-partial', { count: result.failed }))
      setSelected(new Set())
      setConfirming(false)
      refetch()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.folders.delete-failed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.folders.title')}</h4>
        {perms.operate && (
          <button onClick={() => setConfirming(true)} disabled={selected.size === 0} style={deleteBtnStyle}>
            {t('asset-pilot.folders.delete-selected', { count: selected.size })}
          </button>
        )}
      </div>

      <ResponsiveTableWrapper>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 480 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
              {perms.operate && <th style={thStyle}></th>}
              <th style={thStyle}>{t('asset-pilot.columns.id')}</th>
              <th style={thStyle}>{t('asset-pilot.columns.path')}</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map(folder => (
              <tr key={folder.id} style={{ borderBottom: '1px solid #f5f5f5', background: selected.has(folder.id) ? '#e6f4ff' : 'transparent' }}>
                {perms.operate && (
                  <td style={tdStyle}><input type="checkbox" checked={selected.has(folder.id)} onChange={() => toggle(folder.id)} /></td>
                )}
                <td style={tdStyle}>#{folder.id}</td>
                <td style={tdStyle}><ExpandablePath path={folder.path} maxLength={64} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </ResponsiveTableWrapper>

      <Pagination page={data.page} pages={Math.max(1, data.items.length < LIMIT ? page : page + 1)} onPage={setPage} />

      {confirming && (
        <ConfirmDialog
          variant="danger"
          title={t('asset-pilot.folders.delete-title')}
          description={t('asset-pilot.folders.delete-desc', { count: selected.size })}
          confirmLabel={t('asset-pilot.folders.delete')}
          loading={busy}
          onConfirm={() => { void handleDelete() }}
          onCancel={() => setConfirming(false)}
        />
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 12, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13 }
const deleteBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: '#ff4d4f', color: '#fff',
  cursor: 'pointer', fontSize: 13, fontWeight: 500, opacity: 1,
}
