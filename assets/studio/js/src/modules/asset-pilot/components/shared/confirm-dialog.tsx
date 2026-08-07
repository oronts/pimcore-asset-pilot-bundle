import React, { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { modalOverlayStyle, modalSurfaceStyle } from './modal-styles'

interface ConfirmDialogProps {
  title: string
  description: string
  confirmLabel: string
  cancelLabel?: string
  variant: 'danger' | 'warning'
  loading?: boolean
  confirmDisabled?: boolean
  details?: React.ReactNode
  onConfirm: () => void
  onCancel: () => void
}

const variantColors = {
  danger: { btn: 'var(--ap-color-error)', icon: 'var(--ap-color-error)', bg: 'var(--ap-color-error-bg)' },
  warning: { btn: 'var(--ap-color-warning)', icon: 'var(--ap-color-warning)', bg: 'var(--ap-color-warning-bg)' },
}

export const ConfirmDialog: React.FC<ConfirmDialogProps> = ({
  title, description, confirmLabel, cancelLabel, variant, loading = false, confirmDisabled = false, details, onConfirm, onCancel,
}) => {
  const { t } = useTranslation()
  const colors = variantColors[variant]
  const modalRef = useModalDismiss<HTMLDivElement>(onCancel, !loading)
  const titleId = useId()
  const descriptionId = useId()

  return (
    <div role="presentation" style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget && !loading) onCancel() }}>
      <div ref={modalRef} role="alertdialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={descriptionId} tabIndex={-1} style={modalStyle}>
        <div style={{ display: 'flex', gap: 12, marginBottom: 16 }}>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" style={{ flexShrink: 0, marginTop: 2 }}>
            <path d="M12 2L1 21h22L12 2z" fill={colors.bg} stroke={colors.icon} strokeWidth="1.5" />
            <text x="12" y="17" textAnchor="middle" fill={colors.icon} fontSize="var(--ap-font-size)" fontWeight="bold">!</text>
          </svg>
          <div>
            <h4 id={titleId} style={{ margin: '0 0 6px', fontSize: 15, fontWeight: 600, color: 'var(--ap-color-text)' }}>{title}</h4>
            <div id={descriptionId} style={{ fontSize: 13, color: 'var(--ap-color-text-secondary)', lineHeight: '1.5' }}>
              <p style={{ margin: 0 }}>{description}</p>
              {details}
            </div>
          </div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onCancel} disabled={loading} style={cancelBtnStyle}>{cancelLabel ?? t('asset-pilot.common.cancel')}</button>
          <button onClick={onConfirm} disabled={loading || confirmDisabled} style={{ ...confirmBtnStyle, background: colors.btn }}>
            {loading ? t('asset-pilot.operations.processing') : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle, width: 420, maxWidth: '90vw',
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 13, color: 'var(--ap-color-text-secondary)',
}
const confirmBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: 'none', borderRadius: 6, color: 'var(--ap-color-text-light-solid)',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
