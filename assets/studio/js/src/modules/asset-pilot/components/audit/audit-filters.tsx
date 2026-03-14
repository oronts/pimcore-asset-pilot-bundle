import React from 'react'
import { useTranslation } from 'react-i18next'
import type { AuditFilters as Filters } from '../../types'

interface AuditFiltersProps {
  filters: Filters
  onChange: (filters: Filters) => void
  onExport: () => void
}

const statusOptions = ['', 'completed', 'failed', 'skipped', 'pending']

export const AuditFiltersBar: React.FC<AuditFiltersProps> = ({ filters, onChange, onExport }) => {
  const { t } = useTranslation()

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
      <input
        type="text"
        placeholder={t('asset-pilot.audit.filter-class')}
        value={filters.class ?? ''}
        onChange={e => onChange({ ...filters, class: e.target.value || undefined, page: 1 })}
        style={inputStyle}
      />

      <select
        value={filters.status ?? ''}
        onChange={e => onChange({ ...filters, status: e.target.value || undefined, page: 1 })}
        style={selectStyle}
      >
        <option value="">{t('asset-pilot.audit.all-statuses')}</option>
        {statusOptions.filter(Boolean).map(s => <option key={s} value={s}>{s}</option>)}
      </select>

      <input
        type="text"
        placeholder={t('asset-pilot.audit.filter-rule')}
        value={filters.ruleName ?? ''}
        onChange={e => onChange({ ...filters, ruleName: e.target.value || undefined, page: 1 })}
        style={inputStyle}
      />

      <button
        onClick={() => onChange({ page: 1, limit: filters.limit })}
        style={clearBtnStyle}
      >
        {t('asset-pilot.common.clear')}
      </button>

      <div style={{ flex: 1 }} />

      <button onClick={onExport} style={exportBtnStyle}>{t('asset-pilot.audit.export-csv')}</button>
    </div>
  )
}

const inputStyle: React.CSSProperties = { padding: '6px 12px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 13, outline: 'none', width: 150 }
const selectStyle: React.CSSProperties = { padding: '6px 12px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 13, outline: 'none' }
const clearBtnStyle: React.CSSProperties = { padding: '6px 12px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff', cursor: 'pointer', fontSize: 12 }
const exportBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #52c41a', borderRadius: 6, background: '#f6ffed', color: '#52c41a',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
