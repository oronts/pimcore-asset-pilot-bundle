import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { Pagination } from './pagination'
import { ErrorRetry } from './error-retry'
import { CardSkeleton } from './skeleton/card-skeleton'
import { theme } from 'antd'

export type ViewMode = 'list' | 'gallery'

export const ViewToggle: React.FC<{ mode: ViewMode; onChange: (mode: ViewMode) => void }> = ({ mode, onChange }) => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const toggleStyle: React.CSSProperties = { padding: '4px 12px', border: 'none', background: token.colorBgContainer, color: token.colorTextSecondary, cursor: 'pointer', fontSize: token.fontSize }
  const toggleActiveStyle: React.CSSProperties = { ...toggleStyle, background: token.colorPrimary, color: token.colorTextLightSolid, fontWeight: 500 }

  return (
    <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
      <div role="group" aria-label={t('asset-pilot.common.view-mode')} style={{ display: 'inline-flex', border: `1px solid ${token.colorBorder}`, borderRadius: token.borderRadius, overflow: 'hidden' }}>
        <button onClick={() => onChange('list')} aria-pressed={mode === 'list'} style={mode === 'list' ? toggleActiveStyle : toggleStyle}>{t('asset-pilot.common.view-list')}</button>
        <button onClick={() => onChange('gallery')} aria-pressed={mode === 'gallery'} style={mode === 'gallery' ? toggleActiveStyle : toggleStyle}>{t('asset-pilot.common.view-gallery')}</button>
      </div>
    </div>
  )
}

export interface GalleryCard {
  key: string | number
  thumbnailId?: number
  type: string
  fallbackLabel: string
  title: React.ReactNode
  meta?: React.ReactNode
  badges?: React.ReactNode
  actions?: React.ReactNode
  selectId?: number
}

interface Selection {
  selected: Set<number>
  allSelected?: boolean
  toggleSelect: (id: number) => void
  toggleAll?: () => void
}

interface Props<T> {
  data: { items: T[] } | null
  loading: boolean
  error?: string | null
  onRetry?: () => void
  empty: React.ReactNode
  summary?: React.ReactNode
  page: number
  pages: number | null
  hasMore?: boolean
  truncated?: boolean
  onPage: (page: number) => void
  toCard: (item: T, index: number) => GalleryCard
  selection?: Selection
  limit?: number
  onLimit?: (limit: number) => void
  pageSizeOptions?: number[]
}

export function GalleryGrid<T>({ data, loading, error, onRetry, empty, summary, page, pages, hasMore, truncated, onPage, toCard, selection, limit, onLimit, pageSizeOptions }: Props<T>): React.ReactElement | null {
  const { t } = useTranslation()
  const { token } = theme.useToken()

  if (loading) return <CardSkeleton count={8} />
  if (data == null) return error != null ? <ErrorRetry error={error} onRetry={onRetry} /> : null

  const cards = data.items.map(toCard)

  return (
    <div>
      {error != null && <ErrorRetry error={error} onRetry={onRetry} />}
      {cards.length === 0 ? <>{empty}</> : <>
      {(summary != null || (selection?.toggleAll != null)) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
          {selection?.toggleAll != null && (
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: token.fontSize, color: token.colorTextSecondary, cursor: 'pointer' }}>
              <input type="checkbox" checked={selection.allSelected ?? false} onChange={selection.toggleAll} />
              {t('asset-pilot.bulk.select-all')}
            </label>
          )}
          {summary}
        </div>
      )}

      <GalleryCards cards={cards} selection={selection} />
      </>}

      <Pagination page={page} pages={pages} hasMore={hasMore} truncated={truncated} onPage={onPage} limit={limit} onLimit={onLimit} pageSizeOptions={pageSizeOptions} />
    </div>
  )
}

export function GalleryCards({ cards, selection }: { cards: GalleryCard[]; selection?: { selected: Set<number>; toggleSelect: (id: number) => void } }): React.ReactElement {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const cardStyle: React.CSSProperties = { border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadiusLG, overflow: 'hidden', background: token.colorBgContainer }
  const thumbWrapStyle: React.CSSProperties = { position: 'relative', height: 130, background: token.colorFillAlter, display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: 12 }}>
      {cards.map(card => {
        const selectable = selection != null && card.selectId != null
        const isSelected = selectable && selection.selected.has(card.selectId as number)
        return (
          <div key={card.key} style={{ ...cardStyle, borderColor: isSelected ? token.colorPrimary : token.colorBorderSecondary, boxShadow: isSelected ? `0 0 0 1px ${token.colorPrimary}` : 'none' }}>
            <div style={thumbWrapStyle}>
              {selectable && (
                <input type="checkbox" checked={isSelected} onChange={() => selection.toggleSelect(card.selectId as number)} style={checkboxStyle} aria-label={t('asset-pilot.bulk.select-row', { id: card.selectId })} />
              )}
              {card.badges != null && <div style={badgeStyle}>{card.badges}</div>}
              <Thumbnail thumbnailId={card.thumbnailId} type={card.type} fallbackLabel={card.fallbackLabel} />
            </div>
            <div style={{ padding: '8px 10px' }}>
              <div style={{ fontSize: token.fontSize, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{card.title}</div>
              {card.meta != null && <div style={{ marginTop: 4 }}>{card.meta}</div>}
              {card.actions != null && <div style={{ marginTop: 8 }}>{card.actions}</div>}
            </div>
          </div>
        )
      })}
    </div>
  )
}

const Thumbnail: React.FC<{ thumbnailId?: number; type: string; fallbackLabel: string }> = ({ thumbnailId, type, fallbackLabel }) => {
  const [failed, setFailed] = useState(false)
  const { token } = theme.useToken()

  if (thumbnailId != null && type === 'image' && !failed) {
    return (
      <img
        src={assetPilotApi.assetImagePreviewUrl(thumbnailId)}
        alt={fallbackLabel}
        loading="lazy"
        onError={() => setFailed(true)}
        style={{ width: '100%', height: '100%', objectFit: 'cover' }}
      />
    )
  }

  return (
    <div style={{ width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', background: token.colorFillAlter }}>
      <span style={{ fontSize: token.fontSize, fontWeight: 600, color: token.colorTextSecondary, letterSpacing: 0.5 }}>{fallbackLabel.toUpperCase()}</span>
    </div>
  )
}

const checkboxStyle: React.CSSProperties = { position: 'absolute', top: 6, left: 6, zIndex: 1, width: 16, height: 16, cursor: 'pointer' }
const badgeStyle: React.CSSProperties = { position: 'absolute', top: 6, right: 6, zIndex: 1 }
