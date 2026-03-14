import React, { useState } from 'react'
import { truncate } from '../../utils/format'

interface ExpandablePathProps {
  path: string | null
  maxLength?: number
  highlight?: React.ReactNode
}

export const ExpandablePath: React.FC<ExpandablePathProps> = ({ path, maxLength = 40, highlight }) => {
  const [expanded, setExpanded] = useState(false)

  if (!path) return <span>-</span>

  const needsTruncation = path.length > maxLength

  if (!needsTruncation) {
    return <span style={pathStyle}>{highlight ?? path}</span>
  }

  if (expanded) {
    return (
      <span
        style={{ ...pathStyle, cursor: 'pointer', wordBreak: 'break-all' }}
        onClick={() => setExpanded(false)}
        title="Click to collapse"
      >
        {path}
        <span style={collapseIcon}>&#x25B4;</span>
      </span>
    )
  }

  return (
    <span
      style={{ ...pathStyle, cursor: 'pointer' }}
      onClick={() => setExpanded(true)}
      title={path}
    >
      {highlight ?? truncate(path, maxLength)}
      <span style={expandIcon}>&#x25BE;</span>
    </span>
  )
}

const pathStyle: React.CSSProperties = {
  fontFamily: 'monospace',
  fontSize: 11,
  color: '#595959',
  lineHeight: 1.4,
}

const expandIcon: React.CSSProperties = {
  marginLeft: 3,
  fontSize: 9,
  color: '#1677ff',
  verticalAlign: 'middle',
}

const collapseIcon: React.CSSProperties = {
  marginLeft: 3,
  fontSize: 9,
  color: '#1677ff',
  verticalAlign: 'middle',
}
