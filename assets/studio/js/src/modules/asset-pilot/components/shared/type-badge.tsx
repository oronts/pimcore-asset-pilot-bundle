import React from 'react'

const colors: Record<string, { bg: string; fg: string }> = {
  image: { bg: 'var(--ap-color-primary-bg)', fg: 'var(--ap-color-primary)' },
  document: { bg: 'var(--ap-color-warning-bg)', fg: 'var(--ap-color-warning-text)' },
  video: { bg: 'var(--ap-color-primary-bg)', fg: 'var(--ap-color-primary)' },
  audio: { bg: 'var(--ap-color-success-bg)', fg: 'var(--ap-color-success-text)' },
  text: { bg: 'var(--ap-color-fill-secondary)', fg: 'var(--ap-color-text-secondary)' },
  archive: { bg: 'var(--ap-color-error-bg)', fg: 'var(--ap-color-error-text)' },
}

export const TypeBadge: React.FC<{ type: string }> = ({ type }) => {
  const c = colors[type] ?? { bg: 'var(--ap-color-fill-secondary)', fg: 'var(--ap-color-text-secondary)' }
  return <span style={{ padding: '1px 6px', borderRadius: 4, fontSize: 'var(--ap-font-size)', background: c.bg, color: c.fg, fontWeight: 500 }}>{type}</span>
}
