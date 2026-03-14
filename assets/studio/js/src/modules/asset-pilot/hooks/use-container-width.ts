import { useState, useEffect, useRef } from 'react'

export function useContainerWidth(): [React.RefObject<HTMLDivElement | null>, number] {
  const ref = useRef<HTMLDivElement | null>(null)
  const [width, setWidth] = useState(1200)

  useEffect(() => {
    const el = ref.current
    if (el == null) return

    setWidth(el.offsetWidth)

    if (typeof ResizeObserver === 'undefined') return

    let timer: ReturnType<typeof setTimeout> | null = null
    const observer = new ResizeObserver(entries => {
      if (timer != null) clearTimeout(timer)
      timer = setTimeout(() => {
        for (const entry of entries) {
          setWidth(entry.contentRect.width)
        }
      }, 100)
    })

    observer.observe(el)
    return () => {
      observer.disconnect()
      if (timer != null) clearTimeout(timer)
    }
  }, [])

  return [ref, width]
}
