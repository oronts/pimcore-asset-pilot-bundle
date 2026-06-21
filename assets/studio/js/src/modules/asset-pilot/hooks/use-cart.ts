import { useCallback, useEffect, useState } from 'react'

const CART_KEY = 'asset-pilot.cart'

/** Mirrors the backend BulkIds::MAX so a cart can never build a zip request the API would reject. */
export const CART_MAX = 1000

function load(): number[] {
  try {
    const raw = JSON.parse(localStorage.getItem(CART_KEY) ?? '[]')
    return Array.isArray(raw) ? raw.filter((n): n is number => typeof n === 'number') : []
  } catch {
    return []
  }
}

export interface Cart {
  ids: number[]
  count: number
  has: (id: number) => boolean
  /** Adds ids (deduped, capped at CART_MAX). Returns true if the cap truncated the result. */
  add: (ids: number[]) => boolean
  remove: (id: number) => void
  clear: () => void
}

/**
 * A durable asset "cart": a set of asset ids that survives page changes, searches and reloads
 * (localStorage-backed), so a content manager can gather assets across several views and download
 * them jointly as a zip.
 */
export function useCart(): Cart {
  const [ids, setIds] = useState<number[]>(load)

  useEffect(() => {
    const onStorage = (e: StorageEvent): void => { if (e.key === CART_KEY) setIds(load()) }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  const persist = useCallback((next: number[]): void => {
    setIds(next)
    try {
      localStorage.setItem(CART_KEY, JSON.stringify(next))
    } catch {
      // a full or unavailable localStorage just means the cart is in-memory for this session
    }
  }, [])

  const add = useCallback((toAdd: number[]): boolean => {
    const merged = [...new Set([...load(), ...toAdd])]
    const capped = merged.length > CART_MAX
    persist(capped ? merged.slice(0, CART_MAX) : merged)
    return capped
  }, [persist])
  const remove = useCallback((id: number): void => { persist(load().filter(i => i !== id)) }, [persist])
  const clear = useCallback((): void => { persist([]) }, [persist])
  const has = useCallback((id: number): boolean => ids.includes(id), [ids])

  return { ids, count: ids.length, has, add, remove, clear }
}
