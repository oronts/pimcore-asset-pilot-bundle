import React from 'react'
import { useTranslation } from 'react-i18next'
import type { UnusedAssetFilters } from '../../types'
import { FilterPresetDropdown } from './filter-preset-dropdown'

interface UnusedAssetsFiltersProps {
  filters: UnusedAssetFilters
  onChange: (filters: UnusedAssetFilters) => void
}

const typeOptions = ['', 'image', 'document', 'video', 'audio', 'text', 'archive']
const confidenceOptions = ['', 'definitely_unused', 'probably_unused', 'recently_uploaded', 'historically_used']

export const UnusedAssetsFiltersBar: React.FC<UnusedAssetsFiltersProps> = ({ filters, onChange }) => {
  const { t } = useTranslation()

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 16, flexWrap: 'wrap' }}>
      <FilterField label={t('asset-pilot.unused.type-label')}>
        <select
          value={filters.type ?? ''}
          onChange={e => onChange({ ...filters, type: e.target.value || undefined, page: 1 })}
          style={selectStyle}
        >
          {typeOptions.map(tp => <option key={tp} value={tp}>{tp || t('asset-pilot.unused.all-types')}</option>)}
        </select>
      </FilterField>

      <FilterField label={t('asset-pilot.unused.extensions')}>
        <input
          type="text"
          placeholder={t('asset-pilot.unused.extensions-placeholder')}
          value={filters.extension ?? ''}
          onChange={e => onChange({ ...filters, extension: e.target.value || undefined, page: 1 })}
          style={{ ...inputStyle, width: 120 }}
        />
      </FilterField>

      <FilterField label={t('asset-pilot.unused.modified-before')}>
        <input
          type="date"
          value={filters.before ?? ''}
          onChange={e => onChange({ ...filters, before: e.target.value || undefined, page: 1 })}
          style={inputStyle}
        />
      </FilterField>

      <FilterField label={t('asset-pilot.unused.modified-after')}>
        <input
          type="date"
          value={filters.after ?? ''}
          onChange={e => onChange({ ...filters, after: e.target.value || undefined, page: 1 })}
          style={inputStyle}
        />
      </FilterField>

      <FilterField label={t('asset-pilot.unused.folder')}>
        <input
          type="text"
          placeholder={t('asset-pilot.unused.folder-placeholder')}
          value={filters.folder ?? ''}
          onChange={e => onChange({ ...filters, folder: e.target.value || undefined, page: 1 })}
          style={{ ...inputStyle, width: 150 }}
        />
      </FilterField>

      <FilterField label={t('asset-pilot.confidence.label')}>
        <select
          value={filters.confidence ?? ''}
          onChange={e => onChange({ ...filters, confidence: e.target.value || undefined, page: 1 })}
          style={selectStyle}
        >
          {confidenceOptions.map(c => (
            <option key={c} value={c}>
              {c === '' ? t('asset-pilot.confidence.all') : t(`asset-pilot.confidence.${c.replace(/_/g, '-')}`)}
            </option>
          ))}
        </select>
      </FilterField>

      <button onClick={() => onChange({ page: 1, limit: filters.limit })} style={clearBtnStyle}>
        {t('asset-pilot.common.clear')}
      </button>

      <FilterPresetDropdown currentFilters={filters} onLoad={onChange} />
    </div>
  )
}

const FilterField: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
    <label style={{ fontSize: 11, color: '#8c8c8c', fontWeight: 500 }}>{label}</label>
    {children}
  </div>
)

const inputStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const selectStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const clearBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 12, alignSelf: 'flex-end' }
