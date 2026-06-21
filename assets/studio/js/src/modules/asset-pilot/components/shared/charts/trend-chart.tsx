import React from 'react'
import { useContainerWidth } from '../../../hooks/use-container-width'

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

/** Dependency-free SVG area+line trend chart. Width is measured (1:1 coords) so the stroke never distorts. */
export const TrendChart: React.FC<Props> = ({ points, formatValue, color = '#1677ff', height = 120 }) => {
  const [ref, width] = useContainerWidth()
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

  return (
    <div ref={ref} style={{ width: '100%', overflowX: 'hidden' }}>
      <svg width={w} height={height} role="img" aria-label={`trend, latest ${fmt(last.value)}`}>
        <path d={area} fill={color} fillOpacity={0.12} />
        <path d={line} fill="none" stroke={color} strokeWidth={2} strokeLinejoin="round" />
        <circle cx={x(n - 1)} cy={y(last.value)} r={3} fill={color} />
      </svg>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, color: '#8c8c8c', marginTop: 2 }}>
        <span>{points[0].label}</span>
        <span>{last.label}</span>
      </div>
    </div>
  )
}
