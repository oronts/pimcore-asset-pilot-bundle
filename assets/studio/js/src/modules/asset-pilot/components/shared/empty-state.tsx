import React from 'react'

type EmptyStateVariant = 'no-data' | 'no-results' | 'empty-search'

interface EmptyStateProps {
  variant: EmptyStateVariant
  title: string
  description?: string
  action?: { label: string; onClick: () => void }
}

export const EmptyState: React.FC<EmptyStateProps> = ({ variant, title, description, action }) => (
  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', padding: '40px 20px', gap: 8 }}>
    <Illustration variant={variant} />
    <p style={{ margin: 0, fontSize: 14, fontWeight: 600, color: 'var(--ap-color-text-secondary)' }}>{title}</p>
    {description != null && <p style={{ margin: 0, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', textAlign: 'center', maxWidth: 300 }}>{description}</p>}
    {action != null && (
      <button onClick={action.onClick} style={actionBtnStyle}>{action.label}</button>
    )}
  </div>
)

const Illustration: React.FC<{ variant: EmptyStateVariant }> = ({ variant }) => {
  const size = 64
  if (variant === 'no-data') {
    return (
      <svg aria-hidden="true" width={size} height={size} viewBox="0 0 64 64" fill="none">
        <rect x="16" y="8" width="32" height="44" rx="4" stroke="var(--ap-color-border)" strokeWidth="2" fill="var(--ap-color-fill-alter)" />
        <line x1="24" y1="20" x2="40" y2="20" stroke="var(--ap-color-border-secondary)" strokeWidth="2" strokeLinecap="round" />
        <line x1="24" y1="28" x2="36" y2="28" stroke="var(--ap-color-border-secondary)" strokeWidth="2" strokeLinecap="round" />
        <line x1="24" y1="36" x2="40" y2="36" stroke="var(--ap-color-border-secondary)" strokeWidth="2" strokeLinecap="round" />
        <circle cx="44" cy="48" r="10" fill="var(--ap-color-fill-alter)" stroke="var(--ap-color-border)" strokeWidth="2" />
        <line x1="39" y1="48" x2="49" y2="48" stroke="var(--ap-color-text-tertiary)" strokeWidth="2" strokeLinecap="round" />
      </svg>
    )
  }
  if (variant === 'no-results') {
    return (
      <svg aria-hidden="true" width={size} height={size} viewBox="0 0 64 64" fill="none">
        <circle cx="28" cy="28" r="16" stroke="var(--ap-color-border)" strokeWidth="2" fill="var(--ap-color-fill-alter)" />
        <line x1="40" y1="40" x2="54" y2="54" stroke="var(--ap-color-border)" strokeWidth="3" strokeLinecap="round" />
        <line x1="22" y1="22" x2="34" y2="34" stroke="var(--ap-color-text-tertiary)" strokeWidth="2" strokeLinecap="round" />
        <line x1="34" y1="22" x2="22" y2="34" stroke="var(--ap-color-text-tertiary)" strokeWidth="2" strokeLinecap="round" />
      </svg>
    )
  }
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 64 64" fill="none">
      <circle cx="28" cy="28" r="16" stroke="var(--ap-color-border)" strokeWidth="2" fill="var(--ap-color-fill-alter)" />
      <line x1="40" y1="40" x2="54" y2="54" stroke="var(--ap-color-border)" strokeWidth="3" strokeLinecap="round" />
      <line x1="22" y1="28" x2="34" y2="28" stroke="var(--ap-color-text-tertiary)" strokeWidth="2" strokeLinecap="round" />
    </svg>
  )
}

const actionBtnStyle: React.CSSProperties = {
  marginTop: 8,
  padding: '6px 16px',
  border: '1px solid var(--ap-color-border)',
  borderRadius: 6,
  background: 'var(--ap-color-bg-container)',
  cursor: 'pointer',
  fontSize: 'var(--ap-font-size)',
  color: 'var(--ap-color-primary)',
  fontWeight: 500,
}
