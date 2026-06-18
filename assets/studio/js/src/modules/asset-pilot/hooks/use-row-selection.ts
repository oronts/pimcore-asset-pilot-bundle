import { useCallback, useState } from 'react'

interface RowSelection {
  selected: Set<number>
  allSelected: boolean
  toggleSelect: (id: number) => void
  toggleAll: () => void
  clear: () => void
}

/**
 * Checkbox selection for a paginated table: tracks the selected ids, toggles one or all visible rows,
 * and clears (call on page/filter change so a bulk action never hits off-page rows). Shared by the
 * unused-assets and asset-management tabs.
 */
export function useRowSelection(items?: ReadonlyArray<{ id: number }>): RowSelection {
  const [selected, setSelected] = useState<Set<number>>(new Set())

  const allSelected = items != null && items.length > 0 && items.every(i => selected.has(i.id))

  const toggleSelect = useCallback((id: number): void => {
    setSelected(prev => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }, [])

  const toggleAll = useCallback((): void => {
    setSelected(allSelected ? new Set() : new Set((items ?? []).map(i => i.id)))
  }, [allSelected, items])

  const clear = useCallback((): void => setSelected(new Set()), [])

  return { selected, allSelected, toggleSelect, toggleAll, clear }
}
