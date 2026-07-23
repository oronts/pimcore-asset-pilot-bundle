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
      aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
      style={{
        ...baseStyle,
        ...style,
      }}
    >
      <button type="button" onClick={() => onToggle(field)} style={buttonStyle}>
        {label}
        <span aria-hidden="true" style={{ fontSize: 'var(--ap-font-size)', lineHeight: 1, color: active ? 'var(--ap-color-primary)' : 'var(--ap-color-text-secondary)' }}>
          {active ? (direction === 'asc' ? '\u25B2' : '\u25BC') : '\u25B4\u25BE'}
        </span>
      </button>
    </th>
  )
}

const buttonStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: 4, border: 0, background: 'transparent',
  padding: 0, color: 'inherit', font: 'inherit', fontWeight: 'inherit', cursor: 'pointer', whiteSpace: 'nowrap',
}

const baseStyle: React.CSSProperties = {
  textAlign: 'left',
  padding: '6px 4px',
  fontSize: 'var(--ap-font-size)',
  color: 'var(--ap-color-text-secondary)',
  fontWeight: 500,
  whiteSpace: 'nowrap',
}
