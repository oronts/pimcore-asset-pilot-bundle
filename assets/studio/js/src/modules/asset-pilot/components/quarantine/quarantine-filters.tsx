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
  <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
    <label style={{ fontSize: 11, color: '#8c8c8c', fontWeight: 500 }}>{label}</label>
    {children}
  </div>
)

const inputStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const selectStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const clearBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 12, alignSelf: 'flex-end' }
