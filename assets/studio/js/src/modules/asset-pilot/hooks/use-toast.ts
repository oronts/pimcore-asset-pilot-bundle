import { useContext } from 'react'
import { ToastContext } from '../components/shared/toast/toast-context'

export function useToast() {
  const ctx = useContext(ToastContext)
  if (ctx == null) {
    // Fallback: no-op when used outside ToastProvider
    return {
      success: () => {},
      error: () => {},
      info: () => {},
      warning: () => {},
    }
  }

  return {
    success: (message: string) => ctx.addToast('success', message),
    error: (message: string) => ctx.addToast('error', message),
    info: (message: string) => ctx.addToast('info', message),
    warning: (message: string) => ctx.addToast('warning', message),
  }
}
