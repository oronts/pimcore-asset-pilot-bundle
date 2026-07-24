import type React from 'react'

export const maintenanceSummaryStyle: React.CSSProperties = {
  margin: 0,
  color: 'var(--ap-color-text-secondary)',
  fontSize: 'var(--ap-font-size)',
}

export const maintenanceTableWrapperStyle: React.CSSProperties = {
  marginTop: 10,
  maxHeight: 420,
  overflow: 'auto',
  border: '1px solid var(--ap-color-border-secondary)',
  borderRadius: 6,
}

export const maintenanceCaptionStyle: React.CSSProperties = {
  textAlign: 'left',
  padding: 8,
  color: 'var(--ap-color-text-secondary)',
  fontWeight: 600,
}

export const maintenanceHeaderStyle: React.CSSProperties = {
  position: 'sticky',
  top: 0,
  textAlign: 'left',
  padding: 8,
  background: 'var(--ap-color-bg-container)',
  color: 'var(--ap-color-text-secondary)',
  fontWeight: 500,
  whiteSpace: 'nowrap',
}

export const maintenanceCellStyle: React.CSSProperties = {
  padding: 8,
  verticalAlign: 'top',
}
