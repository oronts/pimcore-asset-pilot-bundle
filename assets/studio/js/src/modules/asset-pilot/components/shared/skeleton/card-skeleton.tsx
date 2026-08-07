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
@media (prefers-reduced-motion: reduce) {
  .ap-skeleton { animation: none !important; }
}
`

export const CardSkeleton: React.FC<CardSkeletonProps> = ({ count = 5 }) => {
  injectStyles('ap-skeleton-styles', SKELETON_CSS)

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16 }}>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} style={cardStyle}>
          <div className="ap-skeleton" style={{ ...barStyle, width: 80, height: 12, marginBottom: 8 }} />
          <div className="ap-skeleton" style={{ ...barStyle, width: 48, height: 24 }} />
        </div>
      ))}
    </div>
  )
}

const cardStyle: React.CSSProperties = {
  padding: '16px 20px',
  borderRadius: 8,
  border: '1px solid var(--ap-color-border-secondary)',
  borderLeft: '3px solid var(--ap-color-border-secondary)',
  background: 'var(--ap-color-fill-alter)',
}

const barStyle: React.CSSProperties = {
  borderRadius: 4,
  background: 'var(--ap-color-border-secondary)',
  animation: 'ap-skeleton-pulse 1.5s ease-in-out infinite',
}
