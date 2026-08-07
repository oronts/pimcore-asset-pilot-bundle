import React from 'react'

interface StatCardProps {
  label: string
  value: number
  color: string
}

export const StatCard: React.FC<StatCardProps> = ({ label, value, color }) => (
  <div style={{
    background: 'var(--ap-color-bg-container)',
    borderRadius: 8,
    padding: '16px 20px',
    borderLeft: `3px solid ${color}`,
    boxShadow: 'var(--ap-box-shadow-secondary)',
  }}>
    <p style={{ margin: '0 0 4px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }}>{label}</p>
    <p style={{ margin: 0, fontSize: 28, fontWeight: 700, color, lineHeight: 1.2 }}>{value}</p>
  </div>
)
