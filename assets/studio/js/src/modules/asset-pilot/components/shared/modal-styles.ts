import type React from 'react'

export const modalOverlayStyle: React.CSSProperties = {
  position: 'fixed',
  inset: 0,
  background: 'var(--ap-color-bg-mask)',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  zIndex: 1000,
}

export const modalSurfaceStyle: React.CSSProperties = {
  background: 'var(--ap-color-bg-elevated)',
  borderRadius: 12,
  padding: 24,
  boxShadow: 'var(--ap-box-shadow)',
}
