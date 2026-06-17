import React from 'react'
import type { SortDirection } from '../../hooks/use-sort'

interface SortableHeaderProps {
  label: string
  field: string
  currentField: string | null
  direction: SortDirection
  onToggle: (field: string) => void
  style?: React.CSSProperties
}

export const SortableHeader: React.FC<SortableHeaderProps> = ({
  label, field, currentField, direction, onToggle, style,
}) => {
  const active = currentField === field

  return (
    <th
      role="columnheader"
      aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
      tabIndex={0}
      onClick={() => onToggle(field)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onToggle(field)
        }
      }}
      style={{
        ...baseStyle,
        ...style,
        cursor: 'pointer',
        userSelect: 'none',
      }}
    >
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
        {label}
        <span style={{ fontSize: 10, lineHeight: 1, color: active ? '#1677ff' : '#bfbfbf' }}>
          {active ? (direction === 'asc' ? '\u25B2' : '\u25BC') : '\u25B4\u25BE'}
        </span>
      </span>
    </th>
  )
}

const baseStyle: React.CSSProperties = {
  textAlign: 'left',
  padding: '6px 4px',
  fontSize: 11,
  color: '#8c8c8c',
  fontWeight: 500,
  whiteSpace: 'nowrap',
}
