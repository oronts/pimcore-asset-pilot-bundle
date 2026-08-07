import React, { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { useContainerWidth } from '../../../hooks/use-container-width'
import { theme } from 'antd'
import { visuallyHiddenStyle } from '../visually-hidden-style'

export interface TrendPoint {
  label: string
  value: number
}

interface Props {
  points: TrendPoint[]
  formatValue?: (value: number) => string
  color?: string
  height?: number
}

export const TrendChart: React.FC<Props> = ({ points, formatValue, color, height = 120 }) => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const [ref, width] = useContainerWidth()
  const summaryId = useId()
  const fmt = formatValue ?? ((v: number) => String(v))

  if (points.length === 0) return <div ref={ref} />

  const padX = 4
  const padY = 8
  const w = Math.max(width, 80)
  const values = points.map(p => p.value)
  const maxV = Math.max(...values)
  const minV = Math.min(...values, 0)
  const span = maxV - minV || 1
  const n = points.length

  const x = (i: number): number => n <= 1 ? w / 2 : padX + (i / (n - 1)) * (w - padX * 2)
  const y = (v: number): number => height - padY - ((v - minV) / span) * (height - padY * 2)

  const line = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' ')
  const area = `${line} L${x(n - 1).toFixed(1)},${height} L${x(0).toFixed(1)},${height} Z`
  const last = points[n - 1]
  const chartColor = color ?? token.colorPrimary
  const summary = points.map(point => t('asset-pilot.charts.value-item', { label: point.label, value: fmt(point.value) })).join('; ')

  return (
    <div ref={ref} style={{ width: '100%', overflowX: 'hidden' }}>
      <svg width={w} height={height} role="img" aria-label={t('asset-pilot.charts.trend-latest', { value: fmt(last.value) })} aria-describedby={summaryId}>
        <path d={area} fill={chartColor} fillOpacity={0.12} />
        <path d={line} fill="none" stroke={chartColor} strokeWidth={2} strokeLinejoin="round" />
        <circle cx={x(n - 1)} cy={y(last.value)} r={3} fill={chartColor} />
      </svg>
      <span id={summaryId} style={visuallyHiddenStyle}>{summary}</span>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: token.fontSize, color: token.colorTextSecondary, marginTop: 2 }}>
        <span>{points[0].label}</span>
        <span>{last.label}</span>
      </div>
    </div>
  )
}
