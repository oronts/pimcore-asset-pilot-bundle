import { useState, useCallback, useEffect } from 'react'
import { useUser } from '@pimcore/studio-ui-bundle/modules/auth'

const MAX_PRESETS = 50

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
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []

    return parsed.filter((item): item is FilterPreset<T> => (
      typeof item === 'object'
      && item !== null
      && typeof (item as { name?: unknown }).name === 'string'
      && (item as { name: string }).name.trim() !== ''
      && Object.prototype.hasOwnProperty.call(item, 'filters')
    )).slice(-MAX_PRESETS)
  } catch {
    return []
  }
}

function writeStorage<T>(key: string, presets: FilterPreset<T>[]): void {
  try {
    localStorage.setItem(key, JSON.stringify(presets))
  } catch {
  }
}

export function useFilterPresets<T>(storageKey: string): UseFilterPresetsReturn<T> {
  const { id: userId } = useUser()
  const scopedKey = `${storageKey}.${userId}`
  const [presets, setPresets] = useState<FilterPreset<T>[]>(() => readStorage<T>(scopedKey))

  useEffect(() => {
    setPresets(readStorage<T>(scopedKey))
    const onStorage = (event: StorageEvent): void => {
      if (event.key === scopedKey) setPresets(readStorage<T>(scopedKey))
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [scopedKey])

  const savePreset = useCallback((name: string, filters: T) => {
    const normalizedName = name.trim()
    if (normalizedName === '') return
    setPresets(prev => {
      const next = prev.filter(preset => preset.name !== normalizedName)
        .concat({ name: normalizedName, filters })
        .slice(-MAX_PRESETS)
      writeStorage(scopedKey, next)
      return next
    })
  }, [scopedKey])

  const deletePreset = useCallback((name: string) => {
    setPresets(prev => {
      const next = prev.filter(p => p.name !== name)
      writeStorage(scopedKey, next)
      return next
    })
  }, [scopedKey])

  const loadPreset = useCallback((name: string): T | null => {
    const p = presets.find(p => p.name === name)
    return p?.filters ?? null
  }, [presets])

  return { presets, savePreset, deletePreset, loadPreset }
}
