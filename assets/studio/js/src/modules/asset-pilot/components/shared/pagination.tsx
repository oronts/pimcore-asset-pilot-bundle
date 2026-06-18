import React from 'react'
import { useTranslation } from 'react-i18next'
import { pageNumbers } from '../../utils/format'

interface PaginationProps {
  page: number
  pages: number
  onPage: (page: number) => void
}

export const Pagination: React.FC<PaginationProps> = ({ page, pages, onPage }) => {
  const { t } = useTranslation()

  if (pages <= 1) return null

  return (
    <div style={{ display: 'flex', justifyContent: 'center', gap: 4, marginTop: 16 }}>
      <button onClick={() => onPage(page - 1)} disabled={page <= 1} style={pageBtnStyle}>{t('asset-pilot.common.prev')}</button>
      {pageNumbers(page, pages).map(p => (
        <button key={p} onClick={() => onPage(p)} style={{ ...pageBtnStyle, background: page === p ? '#1677ff' : '#fff', color: page === p ? '#fff' : '#595959' }}>{p}</button>
      ))}
      <button onClick={() => onPage(page + 1)} disabled={page >= pages} style={pageBtnStyle}>{t('asset-pilot.common.next')}</button>
    </div>
  )
}

const pageBtnStyle: React.CSSProperties = { padding: '4px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', cursor: 'pointer', fontSize: 12 }
