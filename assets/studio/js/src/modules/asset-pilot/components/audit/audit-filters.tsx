import React from 'react'
import { useTranslation } from 'react-i18next'
import type { AuditFilters as Filters } from '../../types'
import { theme } from 'antd'
import { OPERATION_STAT_KEYS } from '../../../../i18n/backend-contract'

interface AuditFiltersProps {
  filters: Filters
  onChange: (filters: Filters) => void
  onExport: () => void
}

const statusOptions = OPERATION_STAT_KEYS

export const AuditFiltersBar: React.FC<AuditFiltersProps> = ({ filters, onChange, onExport }) => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const controlStyle: React.CSSProperties = { padding: '6px 12px', border: `1px solid ${token.colorBorder}`, borderRadius: token.borderRadius, fontSize: token.fontSize, outline: 'none', background: token.colorBgContainer, color: token.colorText }

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
      <input
        type="text"
        aria-label={t('asset-pilot.audit.filter-class')}
        placeholder={t('asset-pilot.audit.filter-class')}
        value={filters.class ?? ''}
        onChange={e => onChange({ ...filters, class: e.target.value || undefined, page: 1 })}
        style={{ ...controlStyle, width: 150 }}
      />

      <select
        aria-label={t('asset-pilot.audit.all-statuses')}
        value={filters.status ?? ''}
        onChange={e => onChange({ ...filters, status: e.target.value || undefined, page: 1 })}
        style={controlStyle}
      >
        <option value="">{t('asset-pilot.audit.all-statuses')}</option>
        {statusOptions.map(status => <option key={status} value={status}>{t(`asset-pilot.status.${status}`)}</option>)}
      </select>

      <input
        type="text"
        aria-label={t('asset-pilot.audit.filter-rule')}
        placeholder={t('asset-pilot.audit.filter-rule')}
        value={filters.ruleName ?? ''}
        onChange={e => onChange({ ...filters, ruleName: e.target.value || undefined, page: 1 })}
        style={{ ...controlStyle, width: 150 }}
      />

      <button
        onClick={() => onChange({ page: 1, limit: filters.limit })}
        style={{ ...controlStyle, cursor: 'pointer' }}
      >
        {t('asset-pilot.common.clear')}
      </button>

      <div style={{ flex: 1 }} />

      <button onClick={onExport} style={{ ...controlStyle, paddingInline: 16, borderColor: token.colorSuccessBorder, background: token.colorSuccessBg, color: token.colorText, cursor: 'pointer', fontWeight: 500 }}>{t('asset-pilot.audit.export-csv')}</button>
    </div>
  )
}
