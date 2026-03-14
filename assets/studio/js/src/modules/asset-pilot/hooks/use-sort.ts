import { useState, useCallback } from 'react'

export type SortDirection = 'asc' | 'desc'

interface UseSortReturn<T> {
  sortField: string | null
  sortDirection: SortDirection
  toggleSort: (field: string) => void
  sortedData: (items: T[]) => T[]
  sortParams: { sort?: string; order?: SortDirection }
}

export function useSort<T>(defaultField?: string, defaultDirection: SortDirection = 'asc'): UseSortReturn<T> {
  const [sortField, setSortField] = useState<string | null>(defaultField ?? null)
  const [sortDirection, setSortDirection] = useState<SortDirection>(defaultDirection)

  const toggleSort = useCallback((field: string) => {
    setSortField(prev => {
      if (prev === field) {
        setSortDirection(d => d === 'asc' ? 'desc' : 'asc')
        return field
      }
      setSortDirection('asc')
      return field
    })
  }, [])

  const sortedData = useCallback((items: T[]): T[] => {
    if (sortField == null) return items
    return Array.from(items).sort((a, b) => {
      const av = (a as Record<string, unknown>)[sortField]
      const bv = (b as Record<string, unknown>)[sortField]
      if (av == null && bv == null) return 0
      if (av == null) return 1
      if (bv == null) return -1
      let cmp = 0
      if (typeof av === 'number' && typeof bv === 'number') {
        cmp = av - bv
      } else if (typeof av === 'boolean' && typeof bv === 'boolean') {
        cmp = (av === bv ? 0 : av ? -1 : 1)
      } else {
        cmp = String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' })
      }
      return sortDirection === 'desc' ? -cmp : cmp
    })
  }, [sortField, sortDirection])

  const sortParams = sortField != null ? { sort: sortField, order: sortDirection } : {}

  return { sortField, sortDirection, toggleSort, sortedData, sortParams }
}
