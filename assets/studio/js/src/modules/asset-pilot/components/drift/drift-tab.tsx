import React, { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useRules, useDrift } from '../../hooks/use-asset-pilot-api'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { OpenButton } from '../shared/open-button'
import { GalleryCards, ViewToggle, type ViewMode } from '../shared/gallery-grid'

export const DriftTab: React.FC = () => {
  const { t } = useTranslation()
  const { data: rules, loading: rulesLoading, error: rulesError, refetch: refetchRules } = useRules()
  const [selectedClass, setSelectedClass] = useState<string>('')
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(50)
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const { data, loading, error } = useDrift(selectedClass || null, page, limit)

  const classes = useMemo(
    () => Array.from(new Set((rules ?? []).map(r => r.class))).sort(),
    [rules],
  )

  const onClassChange = (value: string): void => {
    setSelectedClass(value)
    setPage(1)
  }

  // Page off objectsScanned, not the filtered drift rows, or later pages get skipped.
  const hasNext = (data?.objectsScanned ?? 0) >= limit

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 8 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.drift.title')}</h4>
        <select
          aria-label={t('asset-pilot.drift.pick-class')}
          value={selectedClass}
          disabled={rulesLoading || rulesError != null}
          onChange={e => onClassChange(e.target.value)}
          style={{ padding: '5px 8px', borderRadius: 6, border: '1px solid var(--ap-color-border)', fontSize: 13 }}
        >
          <option value="">{t('asset-pilot.drift.pick-class')}</option>
          {classes.map(c => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      {rulesError != null && (
        <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }}>
          {t('asset-pilot.operations.rules-failed', { message: rulesError })} <button onClick={refetchRules}>{t('asset-pilot.common.retry')}</button>
        </p>
      )}
      <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginBottom: 16 }}>{t('asset-pilot.drift.hint')}</p>

      {selectedClass !== '' && <ViewToggle mode={viewMode} onChange={setViewMode} />}

      {selectedClass === '' && (
        <EmptyState variant="no-data" title={t('asset-pilot.drift.choose')} description={t('asset-pilot.drift.choose-desc')} />
      )}

      {selectedClass !== '' && loading && <TableSkeleton rows={4} columns={4} />}

      {selectedClass !== '' && !loading && error != null && (
        <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      )}

      {selectedClass !== '' && !loading && error == null && data?.truncated === true && (
        <p role="status" style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-warning-text-active)', marginBottom: 8 }}>{t('asset-pilot.drift.truncated')}</p>
      )}

      {selectedClass !== '' && !loading && error == null && data != null && (
        data.items.length === 0 && !hasNext && data.truncated !== true
          ? <EmptyState variant="no-results" title={t('asset-pilot.drift.none')} description={t('asset-pilot.drift.none-desc', { count: data.objectsScanned })} />
          : (
            <>
              <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', marginBottom: 8 }}>{t('asset-pilot.drift.scanned', { count: data.objectsScanned })}</p>
              {data.items.length === 0
                ? <p style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', padding: '12px 0' }}>{t('asset-pilot.common.none-on-page')}</p>
                : viewMode === 'gallery'
                ? (
                  <GalleryCards
                    cards={data.items.map((item, i) => ({
                      key: `${item.assetId}-${item.ruleName}-${i}`,
                      thumbnailId: item.assetId,
                      type: 'image',
                      fallbackLabel: item.currentPath.split('.').pop() ?? t('asset-pilot.common.file'),
                      title: <OpenButton id={item.assetId} type="asset" />,
                      meta: (
                        <div style={{ fontSize: 'var(--ap-font-size)' }}>
                          <div style={{ color: 'var(--ap-color-warning-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{item.currentPath}</div>
                          <div style={{ color: 'var(--ap-color-success-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{item.expectedPath}</div>
                          <div style={{ color: item.eligibility === 'blocked' ? 'var(--ap-color-error-text)' : 'var(--ap-color-text-secondary)' }}>{item.reason ?? t(`asset-pilot.drift.eligibility-${item.eligibility}`)}</div>
                        </div>
                      ),
                    }))}
                  />
                )
                : (
                  <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 720 }}>
                      <thead>
                        <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
                          <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                          <th style={thStyle}>{t('asset-pilot.drift.current')}</th>
                          <th style={thStyle}>{t('asset-pilot.drift.expected')}</th>
                          <th style={thStyle}>{t('asset-pilot.columns.rule')}</th>
                          <th style={thStyle}>{t('asset-pilot.drift.eligibility')}</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.items.map((item, i) => (
                          <tr key={`${item.assetId}-${item.ruleName}-${i}`} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                            <td style={tdStyle}><OpenButton id={item.assetId} type="asset" /></td>
                            <td style={{ ...tdStyle, color: 'var(--ap-color-warning-text)' }}><ExpandablePath path={item.currentPath} maxLength={36} /></td>
                            <td style={{ ...tdStyle, color: 'var(--ap-color-success-text)' }}><ExpandablePath path={item.expectedPath} maxLength={36} /></td>
                            <td style={tdStyle}>{item.ruleName}</td>
                            <td style={tdStyle} title={item.reason ?? undefined}>{item.reason ?? t(`asset-pilot.drift.eligibility-${item.eligibility}`)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </ResponsiveTableWrapper>
                )}
              <Pagination page={data.page} pages={hasNext ? page + 1 : page} onPage={setPage} limit={limit} onLimit={n => { setLimit(n); setPage(1) }} />
            </>
          )
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
