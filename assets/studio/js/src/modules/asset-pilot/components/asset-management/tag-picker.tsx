import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useTags } from '../../hooks/use-asset-pilot-api'
import { assetPilotApi } from '../../services/api'

interface TagPickerProps {
  assetIds: number[]
  onDone: (result: string) => void
  onCancel: () => void
}

export const TagPicker: React.FC<TagPickerProps> = ({ assetIds, onDone, onCancel }) => {
  const { t } = useTranslation()
  const { data: tags, loading: tagsLoading } = useTags()
  const [selectedTagIds, setSelectedTagIds] = useState<Set<number>>(new Set())
  const [replace, setReplace] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [search, setSearch] = useState('')

  const toggleTag = (id: number): void => {
    setSelectedTagIds(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const handleSubmit = async (): Promise<void> => {
    if (selectedTagIds.size === 0) return
    setSubmitting(true)
    try {
      const result = await assetPilotApi.bulkTagAssets(assetIds, [...selectedTagIds], replace)
      onDone(`Tagged: ${result.tagged ?? 0}, Failed: ${result.failed}`)
    } catch (e: unknown) {
      onDone(`Error: ${e instanceof Error ? e.message : 'Unknown error'}`)
    } finally {
      setSubmitting(false)
    }
  }

  const filteredTags = (tags ?? []).filter(tag =>
    search === '' || tag.name.toLowerCase().includes(search.toLowerCase()),
  )

  return (
    <div style={{ padding: '8px 0' }}>
      <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 6, color: '#1a1a1a' }}>
        {t('asset-pilot.management.tags-title')}
      </div>

      <input
        type="text"
        placeholder={t('asset-pilot.management.tags-search')}
        value={search}
        onChange={e => setSearch(e.target.value)}
        style={{ ...inputStyle, width: '100%', marginBottom: 6 }}
      />

      <div style={{ maxHeight: 160, overflowY: 'auto', border: '1px solid #f0f0f0', borderRadius: 6, padding: 4 }}>
        {tagsLoading && <div style={{ padding: 8, fontSize: 12, color: '#8c8c8c' }}>{t('asset-pilot.common.loading')}</div>}
        {!tagsLoading && filteredTags.length === 0 && (
          <div style={{ padding: 8, fontSize: 12, color: '#8c8c8c' }}>{t('asset-pilot.management.tags-none')}</div>
        )}
        {filteredTags.map(tag => (
          <label key={tag.id} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '3px 6px', cursor: 'pointer', fontSize: 12 }}>
            <input type="checkbox" checked={selectedTagIds.has(tag.id)} onChange={() => toggleTag(tag.id)} />
            <span>{tag.name}</span>
          </label>
        ))}
      </div>

      <label style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 6, fontSize: 11, color: '#595959', cursor: 'pointer' }}>
        <input type="checkbox" checked={replace} onChange={e => setReplace(e.target.checked)} />
        {t('asset-pilot.management.tags-replace')}
      </label>

      <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
        <button
          onClick={handleSubmit}
          disabled={submitting || selectedTagIds.size === 0}
          style={confirmBtnStyle}
        >
          {submitting ? t('asset-pilot.management.tags-assigning') : t('asset-pilot.management.tags-confirm')}
        </button>
        <button onClick={onCancel} disabled={submitting} style={cancelBtnStyle}>
          {t('asset-pilot.common.cancel')}
        </button>
      </div>
    </div>
  )
}

const inputStyle: React.CSSProperties = { padding: '4px 8px', border: '1px solid #d9d9d9', borderRadius: 4, fontSize: 12, outline: 'none' }
const confirmBtnStyle: React.CSSProperties = { padding: '4px 12px', border: '1px solid #91caff', borderRadius: 4, background: '#e6f4ff', color: '#0958d9', cursor: 'pointer', fontSize: 12, fontWeight: 500 }
const cancelBtnStyle: React.CSSProperties = { padding: '4px 12px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', color: '#595959', cursor: 'pointer', fontSize: 12 }
