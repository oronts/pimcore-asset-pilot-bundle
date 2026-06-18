import { useEffect, useRef } from 'react'

const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function useModalDismiss<T extends HTMLElement>(onClose: () => void): React.RefObject<T> {
  const ref = useRef<T>(null)
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  // Mount-only: re-running on a changed onClose identity (callers pass inline arrows) would
  // re-focus the first control on every render and steal focus from inputs inside the modal.
  useEffect(() => {
    const node = ref.current
    const previouslyFocused = document.activeElement as HTMLElement | null

    const focusable = node?.querySelectorAll<HTMLElement>(FOCUSABLE)
    if (focusable != null && focusable.length > 0) focusable[0].focus()
    else node?.focus()

    const onKeyDown = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onCloseRef.current()
        return
      }
      if (e.key !== 'Tab' || node == null) return
      const items = node.querySelectorAll<HTMLElement>(FOCUSABLE)
      if (items.length === 0) return
      const first = items[0]
      const last = items[items.length - 1]
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      previouslyFocused?.focus?.()
    }
  }, [])

  return ref
}
