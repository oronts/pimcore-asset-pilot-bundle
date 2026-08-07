import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useRowSelection } from './use-row-selection'

interface Row {
  id: number
  locked: boolean
}

const isSelectable = (row: Row): boolean => !row.locked

describe('useRowSelection', () => {
  it('never selects rows rejected by the selectability predicate', () => {
    const items: Row[] = [
      { id: 1, locked: false },
      { id: 2, locked: true },
    ]
    const { result } = renderHook(() => useRowSelection(items, isSelectable))

    act(() => result.current.toggleSelect(2))

    expect(result.current.selected).toEqual(new Set())
  })

  it('selects only selectable visible rows and clears them together', () => {
    const items: Row[] = [
      { id: 1, locked: false },
      { id: 2, locked: true },
      { id: 3, locked: false },
    ]
    const { result } = renderHook(() => useRowSelection(items, isSelectable))

    act(() => result.current.toggleAll())

    expect(result.current.selected).toEqual(new Set([1, 3]))
    expect(result.current.allSelected).toBe(true)

    act(() => result.current.toggleAll())
    expect(result.current.selected).toEqual(new Set())
  })

  it('prunes selected ids when rows leave the visible result set', async () => {
    const { result, rerender } = renderHook(
      ({ items }) => useRowSelection(items, isSelectable),
      { initialProps: { items: [{ id: 1, locked: false }, { id: 2, locked: false }] } },
    )
    act(() => {
      result.current.toggleSelect(1)
      result.current.toggleSelect(2)
    })

    rerender({ items: [{ id: 2, locked: false }, { id: 3, locked: false }] })

    await waitFor(() => expect(result.current.selected).toEqual(new Set([2])))
  })
})
