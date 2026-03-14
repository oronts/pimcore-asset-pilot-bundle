import { useState, useCallback } from 'react'

interface FilterPreset<T> {
  name: string
  filters: T
}

interface UseFilterPresetsReturn<T> {
  presets: FilterPreset<T>[]
  savePreset: (name: string, filters: T) => void
  deletePreset: (name: string) => void
  loadPreset: (name: string) => T | null
}

function readStorage<T>(key: string): FilterPreset<T>[] {
  try {
    const raw = localStorage.getItem(key)
    if (raw == null) return []
    return JSON.parse(raw) as FilterPreset<T>[]
  } catch {
    return []
  }
}

function writeStorage<T>(key: string, presets: FilterPreset<T>[]): void {
  try {
    localStorage.setItem(key, JSON.stringify(presets))
  } catch { /* ignore quota errors */ }
}

export function useFilterPresets<T>(storageKey: string): UseFilterPresetsReturn<T> {
  const [presets, setPresets] = useState<FilterPreset<T>[]>(() => readStorage<T>(storageKey))

  const savePreset = useCallback((name: string, filters: T) => {
    setPresets(prev => {
      const next = prev.filter(p => p.name !== name).concat({ name, filters })
      writeStorage(storageKey, next)
      return next
    })
  }, [storageKey])

  const deletePreset = useCallback((name: string) => {
    setPresets(prev => {
      const next = prev.filter(p => p.name !== name)
      writeStorage(storageKey, next)
      return next
    })
  }, [storageKey])

  const loadPreset = useCallback((name: string): T | null => {
    const p = presets.find(p => p.name === name)
    return p?.filters ?? null
  }, [presets])

  return { presets, savePreset, deletePreset, loadPreset }
}
