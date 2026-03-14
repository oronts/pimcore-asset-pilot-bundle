import React from 'react'
import { injectStyles } from '../../../utils/inject-styles'

interface CardSkeletonProps {
  count?: number
}

const SKELETON_CSS = `
@keyframes ap-skeleton-pulse {
  0% { opacity: 1; }
  50% { opacity: 0.4; }
  100% { opacity: 1; }
}
`

export const CardSkeleton: React.FC<CardSkeletonProps> = ({ count = 5 }) => {
  injectStyles('ap-skeleton-styles', SKELETON_CSS)

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16 }}>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} style={cardStyle}>
          <div style={{ ...barStyle, width: 80, height: 12, marginBottom: 8 }} />
          <div style={{ ...barStyle, width: 48, height: 24 }} />
        </div>
      ))}
    </div>
  )
}

const cardStyle: React.CSSProperties = {
  padding: '16px 20px',
  borderRadius: 8,
  border: '1px solid #f0f0f0',
  borderLeft: '3px solid #e8e8e8',
  background: '#fafafa',
}

const barStyle: React.CSSProperties = {
  borderRadius: 4,
  background: '#e8e8e8',
  animation: 'ap-skeleton-pulse 1.5s ease-in-out infinite',
}
