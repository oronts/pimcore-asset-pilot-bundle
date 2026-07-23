import React from 'react'
import { useTranslation } from 'react-i18next'
import { pageNumbers } from '../../utils/format'

const DEFAULT_PAGE_SIZES = [20, 50, 100, 200]

interface PaginationProps {
  page: number
  pages: number | null
  hasMore?: boolean
  truncated?: boolean
  onPage: (page: number) => void
  limit?: number
  onLimit?: (limit: number) => void
  pageSizeOptions?: number[]
}

export const Pagination: React.FC<PaginationProps> = ({ page, pages, hasMore = false, truncated = false, onPage, limit, onLimit, pageSizeOptions = DEFAULT_PAGE_SIZES }) => {
  const { t } = useTranslation()
  const showSizer = onLimit != null && limit != null
  const numbered = pages != null && pages > 1
  const cursor = pages == null && (page > 1 || hasMore)

  if (!numbered && !cursor && !showSizer && !truncated) return null

  return (
    <>
      {truncated && (
        <p role="status" style={{ textAlign: 'center', margin: '12px 0 0', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-warning, #b26a00)' }}>
          {t('asset-pilot.common.results-truncated')}
        </p>
      )}
      {(numbered || cursor || showSizer) && (
    <nav aria-label={t('asset-pilot.common.pagination')} style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 4, marginTop: 16 }}>
      {numbered && pages != null && (
        <>
          <button onClick={() => onPage(page - 1)} disabled={page <= 1} style={pageBtnStyle}>{t('asset-pilot.common.prev')}</button>
          {pageNumbers(page, pages).map(p => (
            <button key={p} onClick={() => onPage(p)} aria-current={page === p ? 'page' : undefined} aria-label={t('asset-pilot.common.page-number', { page: p })} style={{ ...pageBtnStyle, background: page === p ? 'var(--ap-color-primary)' : 'var(--ap-color-bg-container)', color: page === p ? 'var(--ap-color-bg-container)' : 'var(--ap-color-text-secondary)' }}>{p}</button>
          ))}
          <button onClick={() => onPage(page + 1)} disabled={page >= pages} style={pageBtnStyle}>{t('asset-pilot.common.next')}</button>
        </>
      )}
      {cursor && (
        <>
          <button onClick={() => onPage(page - 1)} disabled={page <= 1} style={pageBtnStyle}>{t('asset-pilot.common.prev')}</button>
          <span aria-current="page" style={{ padding: '4px 10px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.common.page-number', { page })}</span>
          <button onClick={() => onPage(page + 1)} disabled={!hasMore} style={pageBtnStyle}>{t('asset-pilot.common.next')}</button>
        </>
      )}
      {showSizer && (
        <select
          value={limit}
          onChange={e => onLimit(Number(e.target.value))}
          aria-label={t('asset-pilot.common.per-page-label')}
          style={{ ...pageBtnStyle, marginLeft: 8 }}
        >
          {(pageSizeOptions.includes(limit) ? pageSizeOptions : [...pageSizeOptions, limit].sort((a, b) => a - b)).map(n => (
            <option key={n} value={n}>{t('asset-pilot.common.per-page', { count: n })}</option>
          ))}
        </select>
      )}
    </nav>
      )}
    </>
  )
}

const pageBtnStyle: React.CSSProperties = { padding: '4px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }
