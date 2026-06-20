import { useCallback, useState } from 'react'

const CART_KEY = 'asset-pilot.cart'

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
  add: (ids: number[]) => void
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

  const persist = useCallback((next: number[]): void => {
    setIds(next)
    try {
      localStorage.setItem(CART_KEY, JSON.stringify(next))
    } catch {
      // a full or unavailable localStorage just means the cart is in-memory for this session
    }
  }, [])

  const add = useCallback((toAdd: number[]): void => { persist([...new Set([...load(), ...toAdd])]) }, [persist])
  const remove = useCallback((id: number): void => { persist(load().filter(i => i !== id)) }, [persist])
  const clear = useCallback((): void => { persist([]) }, [persist])
  const has = useCallback((id: number): boolean => ids.includes(id), [ids])

  return { ids, count: ids.length, has, add, remove, clear }
}
