import React from 'react'

interface StatCardProps {
  label: string
  value: number
  color: string
}

export const StatCard: React.FC<StatCardProps> = ({ label, value, color }) => (
  <div style={{
    background: '#fff',
    borderRadius: 8,
    padding: '16px 20px',
    borderLeft: `3px solid ${color}`,
    boxShadow: '0 1px 3px rgba(0,0,0,0.06)',
  }}>
    <p style={{ margin: '0 0 4px', fontSize: 12, color: '#8c8c8c', fontWeight: 500 }}>{label}</p>
    <p style={{ margin: 0, fontSize: 28, fontWeight: 700, color, lineHeight: 1.2 }}>{value}</p>
  </div>
)
