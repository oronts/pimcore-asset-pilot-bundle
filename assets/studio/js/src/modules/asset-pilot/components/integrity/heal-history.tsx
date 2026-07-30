import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useHealHistory } from '../../hooks/use-asset-pilot-api'
import { useToast } from '../../hooks/use-toast'
import { assetPilotApi } from '../../services/api'
import type { HealHistoryItem } from '../../types'
import { formatDate } from '../../utils/format'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { EmptyState } from '../shared/empty-state'
import { ExpandablePath } from '../shared/expandable-path'
import { OpenButton } from '../shared/open-button'
import { Pagination } from '../shared/pagination'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'

export const HealHistory: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(25)
  const [pending, setPending] = useState<HealHistoryItem | null>(null)
  const [busy, setBusy] = useState(false)
  const { data, loading, error, refetch } = useHealHistory(page, limit)

  const undo = async (): Promise<void> => {
    if (pending == null || !pending.eligible) return

    setBusy(true)
    try {
      await assetPilotApi.undoHeal(pending.assetId)
      toast.success(t('asset-pilot.integrity.undone', { id: pending.assetId }))
      setPending(null)
      refetch()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.integrity.undo-failed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section style={sectionStyle}>
      <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 600 }}>
        {t('asset-pilot.integrity.history.title', { count: data?.total ?? data?.items.length ?? 0 })}
      </h4>

      {loading
        ? <TableSkeleton rows={3} columns={9} />
        : error != null
          ? (
            <div>
              <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
              <button onClick={refetch} style={secondaryBtnStyle}>{t('asset-pilot.common.retry')}</button>
            </div>
          )
          : data == null || (data.items.length === 0 && data.truncated !== true && data.hasMore !== true)
            ? <EmptyState variant="no-data" title={t('asset-pilot.integrity.history.empty')} description={t('asset-pilot.integrity.history.empty-desc')} />
            : (
              <>
                {data.items.length === 0
                  ? <p role="status" style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', padding: '12px 0' }}>{t('asset-pilot.common.none-on-page')}</p>
                  : (
                <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 960 }}>
                    <thead>
                      <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                        <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                        <th style={thStyle}>{t('asset-pilot.columns.path')}</th>
                        <th style={thStyle}>{t('asset-pilot.integrity.history.from-version')}</th>
                        <th style={thStyle}>{t('asset-pilot.integrity.history.to-version')}</th>
                        <th style={thStyle}>{t('asset-pilot.integrity.history.checker')}</th>
                        <th style={thStyle}>{t('asset-pilot.integrity.history.date')}</th>
                        <th style={thStyle}>{t('asset-pilot.integrity.history.status')}</th>
                        <th style={thStyle}>{t('asset-pilot.integrity.history.eligibility')}</th>
                        <th style={thStyle}>{t('asset-pilot.common.actions')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.items.map(item => (
                        <tr key={item.id} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                          <td style={tdStyle}><OpenButton id={item.assetId} type="asset" /></td>
                          <td style={tdStyle}><ExpandablePath path={item.path} maxLength={42} /></td>
                          <td style={tdStyle}>{item.fromVersion}</td>
                          <td style={tdStyle}>{item.toVersion ?? '—'}</td>
                          <td style={tdStyle}>{item.checker}</td>
                          <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>{formatDate(item.createdAt, true)}</td>
                          <td style={tdStyle}>{t(`asset-pilot.integrity.history.status.${item.status}`)}</td>
                          <td style={tdStyle}>{eligibility(item, t)}</td>
                          <td style={tdStyle}>
                            {item.eligible && (
                              <button onClick={() => setPending(item)} style={undoBtnStyle}>
                                {t('asset-pilot.integrity.undo')}
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </ResponsiveTableWrapper>
                  )}

                <Pagination
                  page={data.page}
                  pages={data.pages}
                  hasMore={data.hasMore}
                  truncated={data.truncated}
                  onPage={setPage}
                  limit={limit}
                  onLimit={nextLimit => { setLimit(nextLimit); setPage(1) }}
                  pageSizeOptions={[10, 25, 50]}
                />
              </>
            )}

      {pending != null && pending.eligible && (
        <ConfirmDialog
          variant="warning"
          title={t('asset-pilot.integrity.undo-title')}
          description={t('asset-pilot.integrity.undo-desc', { id: pending.assetId })}
          confirmLabel={t('asset-pilot.integrity.undo')}
          loading={busy}
          onConfirm={() => { void undo() }}
          onCancel={() => setPending(null)}
        />
      )}
    </section>
  )
}

const eligibility = (item: HealHistoryItem, t: ReturnType<typeof useTranslation>['t']): React.ReactNode => {
  if (item.eligible) {
    return <span style={{ color: 'var(--ap-color-success-text)' }}>{t('asset-pilot.integrity.history.eligible')}</span>
  }

  const reason = item.eligibilityReason == null
    ? item.reason ?? t('asset-pilot.integrity.history.ineligible')
    : t(`asset-pilot.integrity.history.reason.${item.eligibilityReason}`, { defaultValue: item.reason ?? item.eligibilityReason })

  return <span title={item.reason ?? undefined} style={{ color: 'var(--ap-color-text-secondary)' }}>{reason}</span>
}

const sectionStyle: React.CSSProperties = { borderTop: '1px solid var(--ap-color-border-secondary)', marginTop: 24, paddingTop: 20 }
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px', verticalAlign: 'top' }
const secondaryBtnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13 }
const undoBtnStyle: React.CSSProperties = { padding: '3px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-primary)' }
