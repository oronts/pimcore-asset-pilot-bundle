import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SERVER_FILTER_DEBOUNCE_MS, useDebouncedValue } from './use-debounced-value'

describe('useDebouncedValue', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('publishes only the latest value after the server-filter delay', () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value), {
      initialProps: { value: '' },
    })

    rerender({ value: 'p' })
    rerender({ value: 'pn' })
    rerender({ value: 'png' })

    act(() => vi.advanceTimersByTime(SERVER_FILTER_DEBOUNCE_MS - 1))
    expect(result.current).toBe('')

    act(() => vi.advanceTimersByTime(1))
    expect(result.current).toBe('png')
  })
})
