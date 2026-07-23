import { useCallback, useEffect, useMemo, useState } from 'react'

interface RowSelection {
  selected: Set<number>
  allSelected: boolean
  toggleSelect: (id: number) => void
  toggleAll: () => void
  clear: () => void
}

const alwaysSelectable = (): boolean => true

/**
 * Checkbox selection for a paginated table: tracks the selected ids, toggles one or all visible rows,
 * prunes ids that leave the result set, and clears on page/filter changes so a bulk action never
 * hits off-page rows. Shared by the selectable asset-list tabs.
 *
 * Pass isSelectable to keep non-selectable rows (e.g. locked assets) out of selection entirely. The
 * guard lives here, in the one place both the table and the gallery consume, so a locked id can never
 * reach a bulk action regardless of which view is rendered.
 */
export function useRowSelection<T extends { id: number }>(
  items?: ReadonlyArray<T>,
  isSelectable: (item: T) => boolean = alwaysSelectable,
): RowSelection {
  const [selected, setSelected] = useState<Set<number>>(new Set())

  const selectableIds = useMemo(
    () => new Set((items ?? []).filter(isSelectable).map(i => i.id)),
    [items, isSelectable],
  )

  useEffect(() => {
    setSelected(current => {
      const visible = new Set([...current].filter(id => selectableIds.has(id)))

      return visible.size === current.size ? current : visible
    })
  }, [selectableIds])

  const allSelected = selectableIds.size > 0 && [...selectableIds].every(id => selected.has(id))

  const toggleSelect = useCallback((id: number): void => {
    setSelected(prev => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else if (selectableIds.has(id)) {
        next.add(id)
      }
      return next
    })
  }, [selectableIds])

  const toggleAll = useCallback((): void => {
    setSelected(allSelected ? new Set() : new Set(selectableIds))
  }, [allSelected, selectableIds])

  const clear = useCallback((): void => setSelected(new Set()), [])

  return { selected, allSelected, toggleSelect, toggleAll, clear }
}
