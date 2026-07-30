import React, { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useEmptyFolders } from '../../hooks/use-asset-pilot-api'
import { usePermissions } from '../../hooks/use-permissions'
import { useToast } from '../../hooks/use-toast'
import { ApiError, assetPilotApi } from '../../services/api'
import type { EmptyFolderDeleteResult } from '../../types'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { OpenButton } from '../shared/open-button'
import { useRowSelection } from '../../hooks/use-row-selection'
import { visuallyHiddenStyle } from '../shared/visually-hidden-style'

export const EmptyFoldersTab: React.FC = () => {
  const { t } = useTranslation()
  const perms = usePermissions()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(50)
  const { data, loading, error, refetch } = useEmptyFolders(page, limit)
  const { selected, toggleSelect, clear } = useRowSelection(data?.items)
  const [reviewedDeletion, setReviewedDeletion] = useState<{
    ids: number[]
    planToken: string
    result: EmptyFolderDeleteResult
  } | null>(null)
  const [lastResult, setLastResult] = useState<EmptyFolderDeleteResult | null>(null)
  const [busy, setBusy] = useState(false)
  const previewVersion = useRef(0)
  const request = useRef<AbortController | null>(null)
  const selectionKey = [...selected].sort((left, right) => left - right).join(',')

  useEffect(() => () => request.current?.abort(), [])

  useEffect(() => {
    request.current?.abort()
    request.current = null
    previewVersion.current++
    setReviewedDeletion(null)
    setBusy(false)
  }, [selectionKey, page, limit])

  if (loading) return <TableSkeleton rows={4} columns={2} />
  if (error != null) return (
    <div>
      <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  if (data == null) return null
  if (data.items.length === 0 && page === 1 && data.hasMore !== true) {
    return <EmptyState variant="no-data" title={t('asset-pilot.folders.empty')} description={t('asset-pilot.folders.empty-desc')} />
  }

  const goToPage = (nextPage: number): void => {
    request.current?.abort()
    request.current = null
    previewVersion.current++
    setReviewedDeletion(null)
    setPage(nextPage)
    clear()
  }

  const changeLimit = (nextLimit: number): void => {
    request.current?.abort()
    request.current = null
    previewVersion.current++
    setReviewedDeletion(null)
    setLimit(nextLimit)
    setPage(1)
    clear()
  }

  const previewDeletion = async (): Promise<void> => {
    const ids = [...selected].sort((left, right) => left - right)
    if (ids.length === 0) return

    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    const version = ++previewVersion.current
    setBusy(true)
    setReviewedDeletion(null)
    setLastResult(null)
    try {
      const result = await assetPilotApi.previewDeleteEmptyFolders(ids, controller.signal)
      if (controller.signal.aborted || version !== previewVersion.current) return
      if (result.dryRun !== true || result.planToken == null || result.planToken === '') {
        throw new Error(t('asset-pilot.folders.preview-invalid'))
      }
      setReviewedDeletion({ ids, planToken: result.planToken, result })
    } catch (caught) {
      if (controller.signal.aborted || (caught instanceof Error && caught.name === 'AbortError')) return
      toast.error(caught instanceof Error ? caught.message : t('asset-pilot.folders.preview-failed'))
    } finally {
      if (!controller.signal.aborted) setBusy(false)
      if (request.current === controller) request.current = null
    }
  }

  const applyReviewedDeletion = async (): Promise<void> => {
    if (reviewedDeletion == null) return

    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setBusy(true)
    try {
      const result = await assetPilotApi.applyDeleteEmptyFolders(reviewedDeletion.ids, reviewedDeletion.planToken, controller.signal)
      if (controller.signal.aborted) return
      toast.success(t('asset-pilot.folders.deleted', { count: result.deleted }))
      if (result.skipped + result.failed > 0) {
        toast.warning(t('asset-pilot.folders.delete-partial', { count: result.skipped + result.failed }))
      }
      setLastResult(result)
      setReviewedDeletion(null)
      clear()
      refetch()
    } catch (caught) {
      if (controller.signal.aborted || (caught instanceof Error && caught.name === 'AbortError')) return
      setReviewedDeletion(null)
      if (caught instanceof ApiError && caught.status === 409) {
        toast.warning(t('asset-pilot.folders.plan-stale'))
      } else {
        toast.error(caught instanceof Error ? caught.message : t('asset-pilot.folders.delete-failed'))
      }
    } finally {
      if (!controller.signal.aborted) setBusy(false)
      if (request.current === controller) request.current = null
    }
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.folders.title')}</h4>
        {perms.operate && (
          <button onClick={() => { void previewDeletion() }} disabled={selected.size === 0 || busy} style={deleteBtnStyle}>
            {t('asset-pilot.folders.delete-selected', { count: selected.size })}
          </button>
        )}
      </div>

      {lastResult != null && (
        <div role="status" style={resultStyle}>
          <strong>{t('asset-pilot.folders.result-title')}</strong>
          <FolderDeletionResult result={lastResult} />
        </div>
      )}

      {data.items.length === 0
        ? <p style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', padding: '12px 0' }}>{t('asset-pilot.common.none-on-page')}</p>
        : (
          <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 480 }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                  {perms.operate && (
                    <th style={thStyle}><span style={visuallyHiddenStyle}>{t('asset-pilot.folders.selection')}</span></th>
                  )}
                  <th style={thStyle}>{t('asset-pilot.columns.id')}</th>
                  <th style={thStyle}>{t('asset-pilot.columns.path')}</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map(folder => (
                  <tr key={folder.id} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)', background: selected.has(folder.id) ? 'var(--ap-color-primary-bg)' : 'transparent' }}>
                    {perms.operate && (
                      <td style={tdStyle}>
                        <input
                          type="checkbox"
                          checked={selected.has(folder.id)}
                          aria-label={t('asset-pilot.folders.select', { path: folder.path })}
                          onChange={() => toggleSelect(folder.id)}
                        />
                      </td>
                    )}
                    <td style={tdStyle}><OpenButton id={folder.id} type="asset" /></td>
                    <td style={tdStyle}><ExpandablePath path={folder.path} maxLength={64} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ResponsiveTableWrapper>
        )}

      <Pagination page={data.page} pages={Math.max(1, data.hasMore ? page + 1 : page)} onPage={goToPage} limit={limit} onLimit={changeLimit} />

      {reviewedDeletion != null && (
        <ConfirmDialog
          variant="danger"
          title={t('asset-pilot.folders.delete-title')}
          description={t('asset-pilot.folders.delete-desc', { count: reviewedDeletion.ids.length })}
          details={<FolderDeletionResult result={reviewedDeletion.result} />}
          confirmLabel={t('asset-pilot.folders.delete')}
          loading={busy}
          confirmDisabled={(reviewedDeletion.result.eligible ?? 0) === 0}
          onConfirm={() => { void applyReviewedDeletion() }}
          onCancel={() => setReviewedDeletion(null)}
        />
      )}
    </div>
  )
}

const FolderDeletionResult: React.FC<{ result: EmptyFolderDeleteResult }> = ({ result }) => {
  const { t } = useTranslation()
  const errors = Object.entries(result.errors)

  return (
    <div style={{ marginTop: 10 }}>
      <p style={{ margin: '0 0 6px', fontWeight: 600 }}>
        {t('asset-pilot.folders.eligibility-summary', {
          eligible: result.eligible ?? result.deleted,
          skipped: result.skipped,
          failed: result.failed,
        })}
      </p>
      {errors.length > 0 && (
        <ul aria-label={t('asset-pilot.folders.errors')} style={{ margin: 0, paddingLeft: 20, maxHeight: 160, overflowY: 'auto' }}>
          {errors.map(([folderId, reason]) => (
            <li key={folderId}>{t('asset-pilot.folders.folder-error', { id: folderId, reason })}</li>
          ))}
        </ul>
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const resultStyle: React.CSSProperties = { marginBottom: 12, padding: 12, border: '1px solid var(--ap-color-success-border)', borderRadius: 6, background: 'var(--ap-color-success-bg)', color: 'var(--ap-color-success-text-active)', fontSize: 'var(--ap-font-size)' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13 }
const deleteBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, background: 'var(--ap-color-error)', color: 'var(--ap-color-text-light-solid)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500, opacity: 1,
}
