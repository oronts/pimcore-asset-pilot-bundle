import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { truncate } from '../../utils/format'

interface ExpandablePathProps {
  path: string | null
  maxLength?: number
  highlight?: React.ReactNode
}

export const ExpandablePath: React.FC<ExpandablePathProps> = ({ path, maxLength = 40, highlight }) => {
  const { t } = useTranslation()
  const [expanded, setExpanded] = useState(false)

  if (!path) return <span>-</span>

  const needsTruncation = path.length > maxLength

  if (!needsTruncation) {
    return <span style={pathStyle}>{highlight ?? path}</span>
  }

  if (expanded) {
    return (
      <button
        type="button"
        style={{ ...pathStyle, cursor: 'pointer', wordBreak: 'break-all' }}
        onClick={() => setExpanded(false)}
        title={t('asset-pilot.common.collapse')}
        aria-expanded="true"
      >
        {path}
        <span style={collapseIcon}>&#x25B4;</span>
      </button>
    )
  }

  return (
    <button
      type="button"
      style={{ ...pathStyle, cursor: 'pointer' }}
      onClick={() => setExpanded(true)}
      title={path}
      aria-expanded="false"
    >
      {highlight ?? truncate(path, maxLength)}
      <span style={expandIcon}>&#x25BE;</span>
    </button>
  )
}

const pathStyle: React.CSSProperties = {
  fontFamily: 'monospace',
  fontSize: 'var(--ap-font-size)',
  color: 'var(--ap-color-text-secondary)',
  lineHeight: 1.4,
  border: 0,
  background: 'transparent',
  padding: 0,
  textAlign: 'left',
}

const expandIcon: React.CSSProperties = {
  marginLeft: 3,
  fontSize: 9,
  color: 'var(--ap-color-primary)',
  verticalAlign: 'middle',
}

const collapseIcon: React.CSSProperties = {
  marginLeft: 3,
  fontSize: 9,
  color: 'var(--ap-color-primary)',
  verticalAlign: 'middle',
}
