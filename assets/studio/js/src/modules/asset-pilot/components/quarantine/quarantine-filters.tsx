import React from 'react'
import { useTranslation } from 'react-i18next'
import type { QuarantineFilters } from '../../types'

const typeOptions = ['', 'image', 'document', 'video', 'audio', 'text', 'archive']

interface Props {
  filters: QuarantineFilters
  onChange: (filters: QuarantineFilters) => void
}

export const QuarantineFiltersBar: React.FC<Props> = ({ filters, onChange }) => {
  const { t } = useTranslation()
  const active = (filters.type != null && filters.type !== '') || filters.before != null || filters.after != null

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 16, flexWrap: 'wrap' }}>
      <Field label={t('asset-pilot.common.type')}>
        <select
          value={filters.type ?? ''}
          onChange={e => onChange({ ...filters, type: e.target.value || undefined })}
          style={selectStyle}
        >
          {typeOptions.map(tp => <option key={tp} value={tp}>{tp || t('asset-pilot.common.all-types')}</option>)}
        </select>
      </Field>

      <Field label={t('asset-pilot.quarantine.filter-after')}>
        <input type="date" value={filters.after ?? ''} onChange={e => onChange({ ...filters, after: e.target.value || undefined })} style={inputStyle} />
      </Field>

      <Field label={t('asset-pilot.quarantine.filter-before')}>
        <input type="date" value={filters.before ?? ''} onChange={e => onChange({ ...filters, before: e.target.value || undefined })} style={inputStyle} />
      </Field>

      {active && (
        <button onClick={() => onChange({})} style={clearBtnStyle}>{t('asset-pilot.common.clear')}</button>
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
