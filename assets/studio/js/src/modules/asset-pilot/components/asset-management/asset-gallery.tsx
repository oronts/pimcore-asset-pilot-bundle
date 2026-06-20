import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AssetItem } from '../../types'
import { assetPilotApi } from '../../services/api'
import { OpenButton } from '../shared/open-button'
import { TypeBadge } from '../shared/type-badge'
import { LockBadge } from '../shared/lock-badge'
import { Pagination } from '../shared/pagination'
import { CardSkeleton } from '../shared/skeleton/card-skeleton'
import { formatBytes } from '../../utils/format'

interface Selection {
  selected: Set<number>
  allSelected: boolean
  toggleSelect: (id: number) => void
  toggleAll: () => void
}

interface Props {
  data: { items: AssetItem[]; total: number; page: number; pages: number } | null
  loading: boolean
  error?: string | null
  onPage: (page: number) => void
  empty: React.ReactNode
  summary?: React.ReactNode
  selection: Selection
  limit?: number
  onLimit?: (limit: number) => void
}

export const AssetGallery: React.FC<Props> = ({ data, loading, error, onPage, empty, summary, selection, limit, onLimit }) => {
  const { t } = useTranslation()

  if (loading) return <CardSkeleton count={8} />
  if (error != null) return <p style={{ color: '#ff4d4f', fontSize: 13 }}>{error}</p>
  if (data == null) return null
  if (data.items.length === 0) return <>{empty}</>

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#595959', cursor: 'pointer' }}>
          <input type="checkbox" checked={selection.allSelected} onChange={selection.toggleAll} />
          {t('asset-pilot.bulk.select-all')}
        </label>
        {summary}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: 12 }}>
        {data.items.map(asset => {
          const isSelected = selection.selected.has(asset.id)
          return (
            <div key={asset.id} style={{ ...cardStyle, borderColor: isSelected ? '#1677ff' : '#f0f0f0', boxShadow: isSelected ? '0 0 0 1px #1677ff' : 'none' }}>
              <div style={thumbWrapStyle}>
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => selection.toggleSelect(asset.id)}
                  style={checkboxStyle}
                  aria-label={asset.filename}
                />
                {asset.locked && <div style={lockStyle}><LockBadge /></div>}
                <Thumbnail asset={asset} />
              </div>
              <div style={{ padding: '8px 10px' }}>
                <div style={{ fontSize: 12, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  <OpenButton id={asset.id} type="asset" label={asset.filename} />
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4 }}>
                  <TypeBadge type={asset.type} />
                  <span style={{ fontSize: 11, color: '#8c8c8c' }}>{formatBytes(asset.file_size)}</span>
                </div>
              </div>
            </div>
          )
        })}
      </div>

      <Pagination page={data.page} pages={data.pages} onPage={onPage} limit={limit} onLimit={onLimit} />
    </div>
  )
}

const Thumbnail: React.FC<{ asset: AssetItem }> = ({ asset }) => {
  const [failed, setFailed] = useState(false)

  if (asset.type === 'image' && !failed) {
    return (
      <img
        src={assetPilotApi.assetImagePreviewUrl(asset.id)}
        alt={asset.filename}
        loading="lazy"
        onError={() => setFailed(true)}
        style={{ width: '100%', height: '100%', objectFit: 'cover' }}
      />
    )
  }

  const ext = (asset.filename.split('.').pop() ?? asset.type).toUpperCase()
  return (
    <div style={fallbackStyle}>
      <span style={{ fontSize: 13, fontWeight: 600, color: '#8c8c8c', letterSpacing: 0.5 }}>{ext}</span>
    </div>
  )
}

const cardStyle: React.CSSProperties = { border: '1px solid #f0f0f0', borderRadius: 8, overflow: 'hidden', background: '#fff' }
const thumbWrapStyle: React.CSSProperties = { position: 'relative', height: 130, background: '#fafafa', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }
const fallbackStyle: React.CSSProperties = { width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'repeating-linear-gradient(45deg, #fafafa, #fafafa 10px, #f5f5f5 10px, #f5f5f5 20px)' }
const checkboxStyle: React.CSSProperties = { position: 'absolute', top: 6, left: 6, zIndex: 1, width: 16, height: 16, cursor: 'pointer' }
const lockStyle: React.CSSProperties = { position: 'absolute', top: 6, right: 6, zIndex: 1 }
