import React from 'react'

export interface BarItem {
  label: string
  value: number
  color?: string
}

interface Props {
  items: BarItem[]
  formatValue?: (value: number) => string
}

/** Horizontal CSS bar chart: each row is a labelled proportional bar against the max value. */
export const BarChart: React.FC<Props> = ({ items, formatValue }) => {
  const max = Math.max(1, ...items.map(i => i.value))

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {items.map(item => (
        <div key={item.label} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ width: 130, fontSize: 12, color: '#595959', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={item.label}>{item.label}</span>
          <div style={{ flex: 1, background: '#f5f5f5', borderRadius: 4, height: 16, overflow: 'hidden' }}>
            <div style={{ width: `${(item.value / max) * 100}%`, background: item.color ?? '#1677ff', height: '100%', borderRadius: 4, minWidth: item.value > 0 ? 2 : 0 }} />
          </div>
          <span style={{ width: 56, textAlign: 'right', fontSize: 12, fontWeight: 600, color: '#1a1a1a' }}>{formatValue != null ? formatValue(item.value) : item.value}</span>
        </div>
      ))}
    </div>
  )
}
