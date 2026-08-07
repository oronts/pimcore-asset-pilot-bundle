import React from 'react'
import { injectStyles } from '../../../utils/inject-styles'

interface TableSkeletonProps {
  rows?: number
  columns?: number
  hasCheckbox?: boolean
}

const SKELETON_CSS = `
@keyframes ap-skeleton-pulse {
  0% { opacity: 1; }
  50% { opacity: 0.4; }
  100% { opacity: 1; }
}
@media (prefers-reduced-motion: reduce) {
  .ap-skeleton { animation: none !important; }
}
`

export const TableSkeleton: React.FC<TableSkeletonProps> = ({ rows = 5, columns = 5, hasCheckbox = false }) => {
  injectStyles('ap-skeleton-styles', SKELETON_CSS)

  const widths = [60, 120, 180, 100, 80, 140, 90, 70, 110, 100, 80, 60]

  return (
    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
      <thead>
        <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
          {hasCheckbox && (
            <th style={thStyle}><Bar w={16} h={16} /></th>
          )}
          {Array.from({ length: columns }, (_, i) => (
            <th key={i} style={thStyle}><Bar w={widths[i % widths.length]} h={12} /></th>
          ))}
        </tr>
      </thead>
      <tbody>
        {Array.from({ length: rows }, (_, ri) => (
          <tr key={ri} style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
            {hasCheckbox && (
              <td style={tdStyle}><Bar w={16} h={16} /></td>
            )}
            {Array.from({ length: columns }, (_, ci) => (
              <td key={ci} style={tdStyle}>
                <Bar w={widths[(ci + ri) % widths.length]} h={14} />
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  )
}

const Bar: React.FC<{ w: number; h: number }> = ({ w, h }) => (
  <div className="ap-skeleton" style={{
    width: w,
    height: h,
    borderRadius: 4,
    background: 'var(--ap-color-border-secondary)',
    animation: 'ap-skeleton-pulse 1.5s ease-in-out infinite',
  }} />
)

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px' }
const tdStyle: React.CSSProperties = { padding: '6px' }
