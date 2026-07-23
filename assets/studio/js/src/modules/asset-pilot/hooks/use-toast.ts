import { useContext } from 'react'
import { ToastContext } from '../components/shared/toast/toast-context'

export function useToast() {
  const ctx = useContext(ToastContext)
  if (ctx == null) {
    throw new Error('useToast must be used inside ToastProvider')
  }

  return {
    success: (message: string) => ctx.addToast('success', message),
    error: (message: string) => ctx.addToast('error', message),
    info: (message: string) => ctx.addToast('info', message),
    warning: (message: string) => ctx.addToast('warning', message),
  }
}
