import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useBrokenAssets } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import { useToast } from '../../hooks/use-toast'
import { assetPilotApi } from '../../services/api'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { OpenButton } from '../shared/open-button'
import { HealModal } from './heal-modal'

export const IntegrityTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(25)
  const { data, loading, error, refetch } = useBrokenAssets(page, limit)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [healing, setHealing] = useState(false)
  const [undoing, setUndoing] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)

  if (loading) return <TableSkeleton rows={4} columns={4} />
  if (error != null) return (
    <div>
      <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  if (data == null) return null

  // Page off the scanned-source count, not the filtered broken rows, or later pages get skipped.
  const hasNext = data.scanned >= limit
  if (data.items.length === 0 && page === 1 && !hasNext) {
    return <EmptyState variant="no-data" title={t('asset-pilot.integrity.empty')} description={t('asset-pilot.integrity.empty-desc')} />
  }

  const toggle = (id: number): void => {
    setSelected(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const handleUndo = async (): Promise<void> => {
    if (undoing == null) return
    setBusy(true)
    try {
      await assetPilotApi.undoHeal(undoing)
      toast.success(t('asset-pilot.integrity.undone', { id: undoing }))
      setUndoing(null)
      refetch()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.integrity.undo-failed'))
    } finally {
      setBusy(false)
    }
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

      {data.items.length === 0
        ? <p style={{ fontSize: 13, color: '#8c8c8c', padding: '12px 0' }}>{t('asset-pilot.common.none-on-page')}</p>
        : (
          <ResponsiveTableWrapper>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 680 }}>
              <thead>
                <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                  <th style={thStyle}></th>
                  <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.path')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.reason')}</th>
                  {perms.admin && <th style={thStyle}>{t('asset-pilot.common.actions')}</th>}
                </tr>
              </thead>
              <tbody>
                {data.items.map(item => (
                  <tr key={item.id} style={{ borderBottom: '1px solid #f5f5f5', background: selected.has(item.id) ? '#e6f4ff' : 'transparent' }}>
                    <td style={tdStyle}><input type="checkbox" checked={selected.has(item.id)} onChange={() => toggle(item.id)} /></td>
                    <td style={tdStyle}><OpenButton id={item.id} type="asset" /></td>
                    <td style={tdStyle}><ExpandablePath path={item.path} maxLength={48} /></td>
                    <td style={{ ...tdStyle, color: '#fa541c' }}>{item.reason}</td>
                    {perms.admin && (
                      <td style={tdStyle}>
                        <button onClick={() => setUndoing(item.id)} style={actionBtnStyle}>{t('asset-pilot.integrity.undo')}</button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </ResponsiveTableWrapper>
        )}

      <Pagination page={data.page} pages={hasNext ? page + 1 : page} onPage={setPage} limit={limit} onLimit={n => { setLimit(n); setPage(1) }} pageSizeOptions={[20, 25, 50]} />

      {healing && (
        <HealModal
          ids={[...selected]}
          canApply={perms.operate}
          onClose={() => setHealing(false)}
          onHealed={() => { setHealing(false); setSelected(new Set()); refetch() }}
        />
      )}

      {undoing != null && (
        <ConfirmDialog
          variant="warning"
          title={t('asset-pilot.integrity.undo-title')}
          description={t('asset-pilot.integrity.undo-desc', { id: undoing })}
          confirmLabel={t('asset-pilot.integrity.undo')}
          loading={busy}
          onConfirm={() => { void handleUndo() }}
          onCancel={() => setUndoing(null)}
        />
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 12, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 13 }
const healBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: '#52c41a', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const actionBtnStyle: React.CSSProperties = {
  padding: '3px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', cursor: 'pointer', fontSize: 12, color: '#1677ff',
}
