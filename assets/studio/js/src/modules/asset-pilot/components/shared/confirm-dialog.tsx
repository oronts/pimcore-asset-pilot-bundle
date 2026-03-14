import React from 'react'

interface ConfirmDialogProps {
  title: string
  description: string
  confirmLabel: string
  cancelLabel?: string
  variant: 'danger' | 'warning'
  loading?: boolean
  onConfirm: () => void
  onCancel: () => void
}

const variantColors = {
  danger: { btn: '#ff4d4f', icon: '#ff4d4f', bg: '#fff2f0' },
  warning: { btn: '#fa8c16', icon: '#fa8c16', bg: '#fff7e6' },
}

export const ConfirmDialog: React.FC<ConfirmDialogProps> = ({
  title, description, confirmLabel, cancelLabel = 'Cancel', variant, loading = false, onConfirm, onCancel,
}) => {
  const colors = variantColors[variant]

  return (
    <div style={overlayStyle} onClick={onCancel}>
      <div style={modalStyle} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', gap: 12, marginBottom: 16 }}>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" style={{ flexShrink: 0, marginTop: 2 }}>
            <path d="M12 2L1 21h22L12 2z" fill={colors.bg} stroke={colors.icon} strokeWidth="1.5" />
            <text x="12" y="17" textAnchor="middle" fill={colors.icon} fontSize="12" fontWeight="bold">!</text>
          </svg>
          <div>
            <h4 style={{ margin: '0 0 6px', fontSize: 15, fontWeight: 600, color: '#262626' }}>{title}</h4>
            <p style={{ margin: 0, fontSize: 13, color: '#595959', lineHeight: '1.5' }}>{description}</p>
          </div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onCancel} disabled={loading} style={cancelBtnStyle}>{cancelLabel}</button>
          <button onClick={onConfirm} disabled={loading} style={{ ...confirmBtnStyle, background: colors.btn }}>
            {loading ? 'Processing...' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}

const overlayStyle: React.CSSProperties = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', display: 'flex',
  alignItems: 'center', justifyContent: 'center', zIndex: 1000,
}
const modalStyle: React.CSSProperties = {
  background: '#fff', borderRadius: 12, padding: 24, width: 420, maxWidth: '90vw',
  boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff',
  cursor: 'pointer', fontSize: 13, color: '#595959',
}
const confirmBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, color: '#fff',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
