import React from 'react'

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

/** Dependency-free SVG donut: segment arcs drawn with stroke-dasharray, plus a value/percentage legend. */
export const DonutChart: React.FC<Props> = ({ segments, size = 132, centerLabel }) => {
  const total = segments.reduce((sum, s) => sum + Math.max(0, s.value), 0)
  const stroke = 14
  const r = size / 2 - stroke / 2
  const c = size / 2
  const circumference = 2 * Math.PI * r
  let offset = 0

  return (
    <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={centerLabel ?? 'donut chart'}>
        <circle cx={c} cy={c} r={r} fill="none" stroke="#f0f0f0" strokeWidth={stroke} />
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
        <text x={c} y={c - 4} textAnchor="middle" dominantBaseline="central" fontSize={22} fontWeight={600} fill="#1a1a1a">{compact.format(total)}</text>
        {centerLabel != null && <text x={c} y={c + 14} textAnchor="middle" dominantBaseline="central" fontSize={10} fill="#8c8c8c">{centerLabel}</text>}
      </svg>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        {segments.map(s => (
          <div key={s.label} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
            <span style={{ width: 10, height: 10, borderRadius: 2, background: s.color, flexShrink: 0 }} />
            <span style={{ color: '#595959' }}>{s.label}</span>
            <span style={{ fontWeight: 600, color: '#1a1a1a' }}>{compact.format(Math.max(0, s.value))}</span>
            <span style={{ color: '#bfbfbf' }}>{total > 0 ? `${Math.round((Math.max(0, s.value) / total) * 100)}%` : '0%'}</span>
          </div>
        ))}
      </div>
    </div>
  )
}
