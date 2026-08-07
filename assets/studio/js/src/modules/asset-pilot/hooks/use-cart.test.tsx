import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { CART_MAX, useCart } from './use-cart'

describe('useCart', () => {
  beforeEach(() => localStorage.clear())

  it('reports only newly accepted ids, ignoring duplicates already in the cart', () => {
    const { result } = renderHook(() => useCart())

    act(() => { result.current.add([1, 2]) })
    let outcome!: { added: number; capped: boolean }
    act(() => { outcome = result.current.add([2, 3]) })

    expect(outcome).toEqual({ added: 1, capped: false })
    expect(result.current.count).toBe(3)
  })

  it('caps at CART_MAX and reports only the ids actually stored', () => {
    const { result } = renderHook(() => useCart())
    const ids = Array.from({ length: CART_MAX + 5 }, (_, i) => i + 1)
    let outcome!: { added: number; capped: boolean }

    act(() => { outcome = result.current.add(ids) })

    expect(outcome).toEqual({ added: CART_MAX, capped: true })
    expect(result.current.count).toBe(CART_MAX)
  })
})
