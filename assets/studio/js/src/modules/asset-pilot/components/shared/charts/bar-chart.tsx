import React, { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from 'antd'
import { visuallyHiddenStyle } from '../visually-hidden-style'

export interface BarItem {
  label: string
  value: number
  color?: string
}

interface Props {
  items: BarItem[]
  formatValue?: (value: number) => string
}

export const BarChart: React.FC<Props> = ({ items, formatValue }) => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const summaryId = useId()
  const max = Math.max(1, ...items.map(i => Math.max(0, i.value)))
  const fmt = formatValue ?? ((value: number) => String(value))
  const summary = items.map(item => t('asset-pilot.charts.value-item', { label: item.label, value: fmt(item.value) })).join('; ')

  return (
    <div>
      <div role="img" aria-label={t('asset-pilot.charts.bar')} aria-describedby={summaryId} style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        {items.map(item => {
          const v = Math.max(0, item.value)
          return (
            <div key={item.label} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ width: 130, fontSize: token.fontSize, color: token.colorTextSecondary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={item.label}>{item.label}</span>
              <div style={{ flex: 1, background: token.colorFillSecondary, borderRadius: token.borderRadiusSM, height: 16, overflow: 'hidden' }}>
                <div style={{ width: `${(v / max) * 100}%`, background: item.color ?? token.colorPrimary, height: '100%', borderRadius: token.borderRadiusSM, minWidth: v > 0 ? 2 : 0 }} />
              </div>
              <span style={{ width: 56, textAlign: 'right', fontSize: token.fontSize, fontWeight: 600, color: token.colorText, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={fmt(item.value)}>{fmt(item.value)}</span>
            </div>
          )
        })}
      </div>
      <span id={summaryId} style={visuallyHiddenStyle}>{summary}</span>
    </div>
  )
}
