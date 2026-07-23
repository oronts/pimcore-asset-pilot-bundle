import { useEffect, useState } from 'react'

export const SERVER_FILTER_DEBOUNCE_MS = 300

export function useDebouncedValue<T>(value: T, delay = SERVER_FILTER_DEBOUNCE_MS): T {
  const [debouncedValue, setDebouncedValue] = useState(value)

  useEffect(() => {
    const timeout = window.setTimeout(() => setDebouncedValue(value), delay)

    return () => window.clearTimeout(timeout)
  }, [delay, value])

  return debouncedValue
}
