import React from 'react'

const colors: Record<string, { bg: string; fg: string }> = {
  image: { bg: '#e6f4ff', fg: '#1677ff' },
  document: { bg: '#fff7e6', fg: '#fa8c16' },
  video: { bg: '#f9f0ff', fg: '#722ed1' },
  audio: { bg: '#f6ffed', fg: '#52c41a' },
  text: { bg: '#f5f5f5', fg: '#595959' },
  archive: { bg: '#fff1f0', fg: '#cf1322' },
}

export const TypeBadge: React.FC<{ type: string }> = ({ type }) => {
  const c = colors[type] ?? { bg: '#f5f5f5', fg: '#595959' }
  return <span style={{ padding: '1px 6px', borderRadius: 4, fontSize: 11, background: c.bg, color: c.fg, fontWeight: 500 }}>{type}</span>
}
