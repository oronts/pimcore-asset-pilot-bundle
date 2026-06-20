import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { Pagination } from './pagination'
import { CardSkeleton } from './skeleton/card-skeleton'

export type ViewMode = 'list' | 'gallery'

export const ViewToggle: React.FC<{ mode: ViewMode; onChange: (mode: ViewMode) => void }> = ({ mode, onChange }) => {
  const { t } = useTranslation()
  return (
    <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
      <div style={{ display: 'inline-flex', border: '1px solid #d9d9d9', borderRadius: 6, overflow: 'hidden' }}>
        <button onClick={() => onChange('list')} style={mode === 'list' ? toggleActiveStyle : toggleStyle}>{t('asset-pilot.common.view-list')}</button>
        <button onClick={() => onChange('gallery')} style={mode === 'gallery' ? toggleActiveStyle : toggleStyle}>{t('asset-pilot.common.view-gallery')}</button>
      </div>
    </div>
  )
}

const toggleStyle: React.CSSProperties = { padding: '4px 12px', border: 'none', background: '#fff', color: '#595959', cursor: 'pointer', fontSize: 12 }
const toggleActiveStyle: React.CSSProperties = { ...toggleStyle, background: '#1677ff', color: '#fff', fontWeight: 500 }

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
  empty: React.ReactNode
  summary?: React.ReactNode
  page: number
  pages: number
  onPage: (page: number) => void
  toCard: (item: T, index: number) => GalleryCard
  selection?: Selection
  limit?: number
  onLimit?: (limit: number) => void
  pageSizeOptions?: number[]
}

export function GalleryGrid<T>({ data, loading, error, empty, summary, page, pages, onPage, toCard, selection, limit, onLimit, pageSizeOptions }: Props<T>): React.ReactElement | null {
  const { t } = useTranslation()

  if (loading) return <CardSkeleton count={8} />
  if (data == null) return error != null ? <p style={{ color: '#ff4d4f', fontSize: 13 }}>{error}</p> : null

  const cards = data.items.map(toCard)

  return (
    <div>
      {error != null && <p style={{ color: '#ff4d4f', fontSize: 13 }}>{error}</p>}
      {cards.length === 0 ? <>{empty}</> : <>
      {(summary != null || (selection?.toggleAll != null)) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
          {selection?.toggleAll != null && (
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#595959', cursor: 'pointer' }}>
              <input type="checkbox" checked={selection.allSelected ?? false} onChange={selection.toggleAll} />
              {t('asset-pilot.bulk.select-all')}
            </label>
          )}
          {summary}
        </div>
      )}

      <GalleryCards cards={cards} selection={selection} />
      </>}

      <Pagination page={page} pages={pages} onPage={onPage} limit={limit} onLimit={onLimit} pageSizeOptions={pageSizeOptions} />
    </div>
  )
}

/** The responsive card grid only (no loading/empty/pagination), for tabs that own those themselves. */
export function GalleryCards({ cards, selection }: { cards: GalleryCard[]; selection?: { selected: Set<number>; toggleSelect: (id: number) => void } }): React.ReactElement {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: 12 }}>
      {cards.map(card => {
        const selectable = selection != null && card.selectId != null
        const isSelected = selectable && selection.selected.has(card.selectId as number)
        return (
          <div key={card.key} style={{ ...cardStyle, borderColor: isSelected ? '#1677ff' : '#f0f0f0', boxShadow: isSelected ? '0 0 0 1px #1677ff' : 'none' }}>
            <div style={thumbWrapStyle}>
              {selectable && (
                <input type="checkbox" checked={isSelected} onChange={() => selection.toggleSelect(card.selectId as number)} style={checkboxStyle} aria-label={card.fallbackLabel} />
              )}
              {card.badges != null && <div style={badgeStyle}>{card.badges}</div>}
              <Thumbnail thumbnailId={card.thumbnailId} type={card.type} fallbackLabel={card.fallbackLabel} />
            </div>
            <div style={{ padding: '8px 10px' }}>
              <div style={{ fontSize: 12, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{card.title}</div>
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
    <div style={fallbackStyle}>
      <span style={{ fontSize: 13, fontWeight: 600, color: '#8c8c8c', letterSpacing: 0.5 }}>{fallbackLabel.toUpperCase()}</span>
    </div>
  )
}

const cardStyle: React.CSSProperties = { border: '1px solid #f0f0f0', borderRadius: 8, overflow: 'hidden', background: '#fff' }
const thumbWrapStyle: React.CSSProperties = { position: 'relative', height: 130, background: '#fafafa', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }
const fallbackStyle: React.CSSProperties = { width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'repeating-linear-gradient(45deg, #fafafa, #fafafa 10px, #f5f5f5 10px, #f5f5f5 20px)' }
const checkboxStyle: React.CSSProperties = { position: 'absolute', top: 6, left: 6, zIndex: 1, width: 16, height: 16, cursor: 'pointer' }
const badgeStyle: React.CSSProperties = { position: 'absolute', top: 6, right: 6, zIndex: 1 }
