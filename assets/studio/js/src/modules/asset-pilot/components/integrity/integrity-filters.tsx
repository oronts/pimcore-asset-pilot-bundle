import React from 'react'
import { useTranslation } from 'react-i18next'
import type { BrokenAssetFilters } from '../../types'

const typeOptions = ['', 'image', 'document', 'video', 'audio', 'text', 'archive']

interface IntegrityFiltersProps {
  filters: BrokenAssetFilters
  onChange: (filters: BrokenAssetFilters) => void
}

export const IntegrityFilters: React.FC<IntegrityFiltersProps> = ({ filters, onChange }) => {
  const { t } = useTranslation()
  const active = filters.folder != null || filters.type != null || filters.extension != null

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 16, flexWrap: 'wrap' }}>
      <Field label={t('asset-pilot.integrity.filter-folder')}>
        <input
          type="text"
          value={filters.folder ?? ''}
          placeholder={t('asset-pilot.integrity.filter-folder-placeholder')}
          onChange={event => onChange({ ...filters, folder: event.target.value || undefined })}
          style={{ ...inputStyle, width: 190 }}
        />
      </Field>

      <Field label={t('asset-pilot.common.type')}>
        <select
          value={filters.type ?? ''}
          onChange={event => onChange({ ...filters, type: event.target.value || undefined })}
          style={selectStyle}
        >
          {typeOptions.map(type => <option key={type} value={type}>{type || t('asset-pilot.common.all-types')}</option>)}
        </select>
      </Field>

      <Field label={t('asset-pilot.integrity.filter-extension')}>
        <input
          type="text"
          value={filters.extension ?? ''}
          placeholder={t('asset-pilot.integrity.filter-extension-placeholder')}
          onChange={event => onChange({ ...filters, extension: event.target.value || undefined })}
          style={{ ...inputStyle, width: 110 }}
        />
      </Field>

      {active && (
        <button type="button" onClick={() => onChange({})} style={clearBtnStyle}>
          {t('asset-pilot.common.clear')}
        </button>
      )}
    </div>
  )
}

const Field: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
    <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }}>{label}</span>
    {children}
  </label>
)

const inputStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 6, fontSize: 'var(--ap-font-size)', outline: 'none' }
const selectStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 6, fontSize: 'var(--ap-font-size)', outline: 'none' }
const clearBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', alignSelf: 'flex-end' }
