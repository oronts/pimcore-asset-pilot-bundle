import React, { createContext, useState, useCallback, useEffect } from 'react'
import ReactDOM from 'react-dom'
import { injectStyles } from '../../../utils/inject-styles'
import { useTranslation } from 'react-i18next'
import { theme } from 'antd'
import { assetPilotThemeVariables } from '../theme-variables'

export type ToastType = 'success' | 'error' | 'info' | 'warning'

export interface ToastItem {
  id: number
  type: ToastType
  message: string
}

interface ToastContextValue {
  addToast: (type: ToastType, message: string) => void
}

export const ToastContext = createContext<ToastContextValue | null>(null)

let toastIdCounter = 0

const TOAST_CSS = `
@keyframes ap-toast-slide-in {
  from { transform: translateX(100%); opacity: 0; }
  to { transform: translateX(0); opacity: 1; }
}
@keyframes ap-toast-fade-out {
  from { opacity: 1; }
  to { opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .ap-toast { animation: none !important; }
}
`

const borderColors: Record<ToastType, string> = {
  success: 'var(--ap-color-success)',
  error: 'var(--ap-color-error)',
  info: 'var(--ap-color-primary)',
  warning: 'var(--ap-color-warning)',
}

const bgColors: Record<ToastType, string> = {
  success: 'var(--ap-color-success-bg)',
  error: 'var(--ap-color-error-bg)',
  info: 'var(--ap-color-primary-bg)',
  warning: 'var(--ap-color-warning-bg)',
}

const iconMap: Record<ToastType, string> = {
  success: '\u2713',
  error: '\u2717',
  info: '\u2139',
  warning: '\u26A0',
}

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { token } = theme.useToken()
  const [toasts, setToasts] = useState<ToastItem[]>([])

  useEffect(() => { injectStyles('ap-toast-styles', TOAST_CSS) }, [])

  const removeToast = useCallback((id: number) => {
    setToasts(prev => prev.filter(t => t.id !== id))
  }, [])

  const addToast = useCallback((type: ToastType, message: string) => {
    const id = ++toastIdCounter
    setToasts(prev => {
      const next = [...prev, { id, type, message }]
      return next.length > 5 ? next.slice(-5) : next
    })
    setTimeout(() => removeToast(id), 4000)
  }, [removeToast])

  const container = (
    <div style={{ ...assetPilotThemeVariables(token), ...containerStyle }}>
      {toasts.map(toast => (
        <ToastNotification key={toast.id} toast={toast} onClose={() => removeToast(toast.id)} />
      ))}
    </div>
  )

  return (
    <ToastContext.Provider value={{ addToast }}>
      {children}
      {typeof document !== 'undefined' && ReactDOM.createPortal(container, document.body)}
    </ToastContext.Provider>
  )
}

const ToastNotification: React.FC<{ toast: ToastItem; onClose: () => void }> = ({ toast, onClose }) => {
  const { t } = useTranslation()
  const [exiting, setExiting] = useState(false)

  useEffect(() => {
    const timer = setTimeout(() => setExiting(true), 3600)
    return () => clearTimeout(timer)
  }, [])

  return (
    <div className="ap-toast" role={toast.type === 'error' ? 'alert' : 'status'} aria-live={toast.type === 'error' ? 'assertive' : 'polite'} style={{
      ...toastStyle,
      borderLeft: `4px solid ${borderColors[toast.type]}`,
      background: bgColors[toast.type],
      animation: exiting ? 'ap-toast-fade-out 0.3s ease forwards' : 'ap-toast-slide-in 0.3s ease',
    }}>
      <span style={{ color: borderColors[toast.type], fontWeight: 700, fontSize: 14, flexShrink: 0 }}>
        {iconMap[toast.type]}
      </span>
      <span style={{ flex: 1, fontSize: 13, color: 'var(--ap-color-text)', lineHeight: '1.4' }}>{toast.message}</span>
      <button onClick={onClose} aria-label={t('asset-pilot.common.close-notification')} style={closeBtnStyle}>&times;</button>
    </div>
  )
}

const containerStyle: React.CSSProperties = {
  position: 'fixed',
  top: 16,
  right: 16,
  zIndex: 99999,
  display: 'flex',
  flexDirection: 'column',
  gap: 8,
  width: 380,
  maxWidth: 380,
  maxInlineSize: 'calc(100vw - 32px)',
  pointerEvents: 'none',
}

const toastStyle: React.CSSProperties = {
  display: 'flex',
  alignItems: 'flex-start',
  gap: 10,
  padding: '12px 16px',
  borderRadius: 8,
  boxShadow: 'var(--ap-box-shadow-secondary)',
  pointerEvents: 'auto',
}

const closeBtnStyle: React.CSSProperties = {
  border: 'none',
  background: 'none',
  fontSize: 18,
  cursor: 'pointer',
  color: 'var(--ap-color-text-secondary)',
  padding: 0,
  lineHeight: 1,
  flexShrink: 0,
}
