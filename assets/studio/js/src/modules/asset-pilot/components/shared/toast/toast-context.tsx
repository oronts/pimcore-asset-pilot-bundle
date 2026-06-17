import React, { createContext, useState, useCallback, useEffect } from 'react'
import ReactDOM from 'react-dom'
import { injectStyles } from '../../../utils/inject-styles'

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
`

const borderColors: Record<ToastType, string> = {
  success: '#52c41a',
  error: '#ff4d4f',
  info: '#1677ff',
  warning: '#fa8c16',
}

const bgColors: Record<ToastType, string> = {
  success: '#f6ffed',
  error: '#fff2f0',
  info: '#e6f4ff',
  warning: '#fff7e6',
}

const iconMap: Record<ToastType, string> = {
  success: '\u2713',
  error: '\u2717',
  info: '\u2139',
  warning: '\u26A0',
}

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
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
    <div style={containerStyle}>
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
  const [exiting, setExiting] = useState(false)

  useEffect(() => {
    const timer = setTimeout(() => setExiting(true), 3600)
    return () => clearTimeout(timer)
  }, [])

  return (
    <div style={{
      ...toastStyle,
      borderLeft: `4px solid ${borderColors[toast.type]}`,
      background: bgColors[toast.type],
      animation: exiting ? 'ap-toast-fade-out 0.3s ease forwards' : 'ap-toast-slide-in 0.3s ease',
    }}>
      <span style={{ color: borderColors[toast.type], fontWeight: 700, fontSize: 14, flexShrink: 0 }}>
        {iconMap[toast.type]}
      </span>
      <span style={{ flex: 1, fontSize: 13, color: '#262626', lineHeight: '1.4' }}>{toast.message}</span>
      <button onClick={onClose} style={closeBtnStyle}>&times;</button>
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
  maxWidth: 380,
  pointerEvents: 'none',
}

const toastStyle: React.CSSProperties = {
  display: 'flex',
  alignItems: 'flex-start',
  gap: 10,
  padding: '12px 16px',
  borderRadius: 8,
  boxShadow: '0 4px 16px rgba(0,0,0,0.1)',
  pointerEvents: 'auto',
}

const closeBtnStyle: React.CSSProperties = {
  border: 'none',
  background: 'none',
  fontSize: 18,
  cursor: 'pointer',
  color: '#8c8c8c',
  padding: 0,
  lineHeight: 1,
  flexShrink: 0,
}
