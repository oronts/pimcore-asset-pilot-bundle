import { useCallback, useEffect, useState } from 'react'
import { useUser } from '@pimcore/studio-ui-bundle/modules/auth'

const CART_KEY = 'asset-pilot.cart'
export const CART_MAX = 1000

function load(key: string): number[] {
  try {
    const raw: unknown = JSON.parse(localStorage.getItem(key) ?? '[]')
    return Array.isArray(raw)
      ? raw.filter((id): id is number => Number.isInteger(id) && id > 0).slice(0, CART_MAX)
      : []
  } catch {
    return []
  }
}

export interface Cart {
  ids: number[]
  count: number
  has: (id: number) => boolean
  add: (ids: number[]) => { added: number; capped: boolean }
  remove: (id: number) => void
  clear: () => void
}

export function useCart(): Cart {
  const { id: userId } = useUser()
  const storageKey = `${CART_KEY}.${userId}`
  const [ids, setIds] = useState<number[]>(() => load(storageKey))

  useEffect(() => {
    setIds(load(storageKey))
    const onStorage = (event: StorageEvent): void => {
      if (event.key === storageKey) setIds(load(storageKey))
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [storageKey])

  const persist = useCallback((next: number[]): void => {
    setIds(next)
    try {
      localStorage.setItem(storageKey, JSON.stringify(next))
    } catch {
    }
  }, [storageKey])

  const add = useCallback((toAdd: number[]): { added: number; capped: boolean } => {
    const validIds = toAdd.filter(id => Number.isInteger(id) && id > 0)
    const prev = load(storageKey)
    const merged = [...new Set([...prev, ...validIds])]
    const capped = merged.length > CART_MAX
    const next = capped ? merged.slice(0, CART_MAX) : merged
    persist(next)
    return { added: next.length - prev.length, capped }
  }, [persist, storageKey])
  const remove = useCallback((id: number): void => { persist(load(storageKey).filter(item => item !== id)) }, [persist, storageKey])
  const clear = useCallback((): void => { persist([]) }, [persist])
  const has = useCallback((id: number): boolean => ids.includes(id), [ids])

  return { ids, count: ids.length, has, add, remove, clear }
}
