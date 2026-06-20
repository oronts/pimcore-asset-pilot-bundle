import React, { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useRules, useDrift } from '../../hooks/use-asset-pilot-api'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { Pagination } from '../shared/pagination'
import { ExpandablePath } from '../shared/expandable-path'
import { OpenButton } from '../shared/open-button'

export const DriftTab: React.FC = () => {
  const { t } = useTranslation()
  const { data: rules } = useRules()
  const [selectedClass, setSelectedClass] = useState<string>('')
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(50)
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
          value={selectedClass}
          onChange={e => onClassChange(e.target.value)}
          style={{ padding: '5px 8px', borderRadius: 6, border: '1px solid #d9d9d9', fontSize: 13 }}
        >
          <option value="">{t('asset-pilot.drift.pick-class')}</option>
          {classes.map(c => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 16 }}>{t('asset-pilot.drift.hint')}</p>

      {selectedClass === '' && (
        <EmptyState variant="no-data" title={t('asset-pilot.drift.choose')} description={t('asset-pilot.drift.choose-desc')} />
      )}

      {selectedClass !== '' && loading && <TableSkeleton rows={4} columns={4} />}

      {selectedClass !== '' && !loading && error != null && (
        <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      )}

      {selectedClass !== '' && !loading && error == null && data != null && (
        data.items.length === 0 && !hasNext
          ? <EmptyState variant="no-results" title={t('asset-pilot.drift.none')} description={t('asset-pilot.drift.none-desc', { count: data.objectsScanned })} />
          : (
            <>
              <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>{t('asset-pilot.drift.scanned', { count: data.objectsScanned })}</p>
              {data.items.length === 0
                ? <p style={{ fontSize: 13, color: '#8c8c8c', padding: '12px 0' }}>{t('asset-pilot.common.none-on-page')}</p>
                : (
                  <ResponsiveTableWrapper>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 720 }}>
                      <thead>
                        <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                          <th style={thStyle}>{t('asset-pilot.columns.asset-id')}</th>
                          <th style={thStyle}>{t('asset-pilot.drift.current')}</th>
                          <th style={thStyle}>{t('asset-pilot.drift.expected')}</th>
                          <th style={thStyle}>{t('asset-pilot.columns.rule')}</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.items.map(item => (
                          <tr key={item.assetId} style={{ borderBottom: '1px solid #f5f5f5' }}>
                            <td style={tdStyle}><OpenButton id={item.assetId} type="asset" /></td>
                            <td style={{ ...tdStyle, color: '#fa541c' }}><ExpandablePath path={item.currentPath} maxLength={36} /></td>
                            <td style={{ ...tdStyle, color: '#52c41a' }}><ExpandablePath path={item.expectedPath} maxLength={36} /></td>
                            <td style={tdStyle}>{item.ruleName}</td>
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

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 12, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
