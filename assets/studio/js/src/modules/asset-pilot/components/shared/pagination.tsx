import React from 'react'
import { useTranslation } from 'react-i18next'
import { pageNumbers } from '../../utils/format'

const DEFAULT_PAGE_SIZES = [20, 50, 100, 200]

interface PaginationProps {
  page: number
  pages: number
  onPage: (page: number) => void
  limit?: number
  onLimit?: (limit: number) => void
  pageSizeOptions?: number[]
}

export const Pagination: React.FC<PaginationProps> = ({ page, pages, onPage, limit, onLimit, pageSizeOptions = DEFAULT_PAGE_SIZES }) => {
  const { t } = useTranslation()
  const showSizer = onLimit != null && limit != null

  if (pages <= 1 && !showSizer) return null

  return (
    <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 4, marginTop: 16 }}>
      {pages > 1 && (
        <>
          <button onClick={() => onPage(page - 1)} disabled={page <= 1} style={pageBtnStyle}>{t('asset-pilot.common.prev')}</button>
          {pageNumbers(page, pages).map(p => (
            <button key={p} onClick={() => onPage(p)} style={{ ...pageBtnStyle, background: page === p ? '#1677ff' : '#fff', color: page === p ? '#fff' : '#595959' }}>{p}</button>
          ))}
          <button onClick={() => onPage(page + 1)} disabled={page >= pages} style={pageBtnStyle}>{t('asset-pilot.common.next')}</button>
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
    </div>
  )
}

const pageBtnStyle: React.CSSProperties = { padding: '4px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', cursor: 'pointer', fontSize: 12 }
