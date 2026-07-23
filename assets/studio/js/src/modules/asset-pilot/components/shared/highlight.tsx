import React from 'react'

interface HighlightProps {
  text: string
  query: string | undefined | null
}

export const Highlight: React.FC<HighlightProps> = ({ text, query }) => {
  if (!query || query.trim() === '') return <>{text}</>

  const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const parts = text.split(new RegExp(`(${escaped})`, 'gi'))

  return (
    <>
      {parts.map((part, i) =>
        part.toLowerCase() === query.toLowerCase()
          ? <span key={i} style={hlStyle}>{part}</span>
          : part,
      )}
    </>
  )
}

const hlStyle: React.CSSProperties = {
  background: 'var(--ap-color-warning-bg)',
  padding: '1px 0',
  borderRadius: 2,
}
