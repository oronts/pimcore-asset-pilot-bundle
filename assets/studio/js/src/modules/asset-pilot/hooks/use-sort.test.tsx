import React, { StrictMode, type ReactNode } from 'react'
import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useSort } from './use-sort'

interface Item {
  name: string | null
  value: number
}

const strictMode = ({ children }: { children: ReactNode }): React.ReactElement => (
  <StrictMode>{children}</StrictMode>
)

describe('useSort', () => {
  it('toggles exactly once under StrictMode', () => {
    const { result } = renderHook(() => useSort<Item>('name'), { wrapper: strictMode })

    act(() => result.current.toggleSort('name'))

    expect(result.current.sortField).toBe('name')
    expect(result.current.sortDirection).toBe('desc')
    expect(result.current.sortParams).toEqual({ sort: 'name', order: 'desc' })
  })

  it('selects a new field in ascending order', () => {
    const { result } = renderHook(() => useSort<Item>('name', 'desc'))

    act(() => result.current.toggleSort('value'))

    expect(result.current.sortField).toBe('value')
    expect(result.current.sortDirection).toBe('asc')
  })

  it('sorts without mutating the input and keeps null values last', () => {
    const items: Item[] = [
      { name: null, value: 2 },
      { name: 'item 10', value: 3 },
      { name: 'Item 2', value: 1 },
    ]
    const { result } = renderHook(() => useSort<Item>('name'))

    const sorted = result.current.sortedData(items)

    expect(sorted.map(item => item.name)).toEqual(['Item 2', 'item 10', null])
    expect(items[0].name).toBeNull()
  })
})
