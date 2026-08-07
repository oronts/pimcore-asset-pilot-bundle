import React, { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from 'antd'
import { visuallyHiddenStyle } from '../visually-hidden-style'

export interface DonutSegment {
  label: string
  value: number
  color: string
}

interface Props {
  segments: DonutSegment[]
  size?: number
  centerLabel?: string
}

const compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 })

export const DonutChart: React.FC<Props> = ({ segments, size = 132, centerLabel }) => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const summaryId = useId()
  const total = segments.reduce((sum, s) => sum + Math.max(0, s.value), 0)
  const stroke = 14
  const r = size / 2 - stroke / 2
  const c = size / 2
  const circumference = 2 * Math.PI * r
  const summary = segments.map(segment => {
    const value = Math.max(0, segment.value)
    const percentage = total > 0 ? Math.round((value / total) * 100) : 0

    return t('asset-pilot.charts.summary-item', { label: segment.label, value: compact.format(value), percentage })
  }).join('; ')
  let offset = 0

  return (
    <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={centerLabel ?? t('asset-pilot.charts.donut')} aria-describedby={summaryId}>
        <circle cx={c} cy={c} r={r} fill="none" stroke={token.colorBorderSecondary} strokeWidth={stroke} />
        {total > 0 && segments.filter(s => s.value > 0).map(s => {
          const dash = (s.value / total) * circumference
          const el = (
            <circle
              key={s.label}
              cx={c} cy={c} r={r} fill="none" stroke={s.color} strokeWidth={stroke}
              strokeDasharray={`${dash} ${circumference - dash}`}
              strokeDashoffset={-offset}
              transform={`rotate(-90 ${c} ${c})`}
            />
          )
          offset += dash
          return el
        })}
        <text x={c} y={c - 4} textAnchor="middle" dominantBaseline="central" fontSize={token.fontSizeHeading3} fontWeight={600} fill={token.colorText}>{compact.format(total)}</text>
        {centerLabel != null && <text x={c} y={c + 16} textAnchor="middle" dominantBaseline="central" fontSize={token.fontSize} fill={token.colorTextSecondary}>{centerLabel}</text>}
      </svg>
      <span id={summaryId} style={visuallyHiddenStyle}>{summary}</span>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        {segments.map(s => (
          <div key={s.label} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: token.fontSize }}>
            <span style={{ width: 10, height: 10, borderRadius: 2, background: s.color, flexShrink: 0 }} />
            <span style={{ color: token.colorTextSecondary }}>{s.label}</span>
            <span style={{ fontWeight: 600, color: token.colorText }}>{compact.format(Math.max(0, s.value))}</span>
            <span style={{ color: token.colorTextTertiary }}>{total > 0 ? `${Math.round((Math.max(0, s.value) / total) * 100)}%` : '0%'}</span>
          </div>
        ))}
      </div>
    </div>
  )
}
