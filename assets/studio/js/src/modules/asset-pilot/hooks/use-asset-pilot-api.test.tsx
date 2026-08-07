import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { assetPilotApi } from '../services/api'
import { SERVER_FILTER_DEBOUNCE_MS } from './use-debounced-value'
import { useAsyncData, useUnusedAssets } from './use-asset-pilot-api'

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

interface Deferred<T> {
  promise: Promise<T>
  resolve: (value: T) => void
  reject: (reason: Error) => void
}

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void
  let reject!: (reason: Error) => void
  const promise = new Promise<T>((onResolve, onReject) => {
    resolve = onResolve
    reject = onReject
  })

  return { promise, resolve, reject }
}

describe('useAsyncData', () => {
  it('aborts the old request and ignores its late result when the fetcher changes', async () => {
    const first = deferred<string>()
    const second = deferred<string>()
    let firstSignal: AbortSignal | undefined
    let secondSignal: AbortSignal | undefined
    const firstFetcher = vi.fn((signal: AbortSignal) => {
      firstSignal = signal
      return first.promise
    })
    const secondFetcher = vi.fn((signal: AbortSignal) => {
      secondSignal = signal
      return second.promise
    })
    const { result, rerender } = renderHook(
      ({ fetcher }) => useAsyncData(fetcher),
      { initialProps: { fetcher: firstFetcher } },
    )

    expect(firstFetcher).toHaveBeenCalledOnce()
    rerender({ fetcher: secondFetcher })

    expect(firstSignal?.aborted).toBe(true)
    expect(secondSignal?.aborted).toBe(false)

    await act(async () => {
      second.resolve('latest')
      await second.promise
    })
    expect(result.current.data).toBe('latest')

    await act(async () => {
      first.resolve('stale')
      await first.promise
    })
    expect(result.current.data).toBe('latest')
  })

  it('aborts the active request and starts a new one when refetched', () => {
    const requests: Array<{ deferred: Deferred<string>; signal: AbortSignal }> = []
    const fetcher = vi.fn((signal: AbortSignal) => {
      const pending = deferred<string>()
      requests.push({ deferred: pending, signal })
      return pending.promise
    })
    const { result } = renderHook(() => useAsyncData(fetcher))

    act(() => result.current.refetch())

    expect(requests).toHaveLength(2)
    expect(requests[0].signal.aborted).toBe(true)
    expect(requests[1].signal.aborted).toBe(false)
  })

  it('aborts the active request on unmount', () => {
    let signal: AbortSignal | undefined
    const fetcher = vi.fn((requestSignal: AbortSignal) => {
      signal = requestSignal
      return new Promise<string>(() => undefined)
    })
    const { unmount } = renderHook(() => useAsyncData(fetcher))

    unmount()

    expect(signal?.aborted).toBe(true)
  })

  it('surfaces non-abort errors and finishes loading', async () => {
    const fetcher = vi.fn(async () => {
      throw new Error('network unavailable')
    })
    const { result } = renderHook(() => useAsyncData(fetcher))

    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.data).toBeNull()
    expect(result.current.error).toBe('network unavailable')
  })
})

describe('server filter requests', () => {
  it('sends only the latest typed filter after the debounce interval', () => {
    vi.useFakeTimers()
    const request = vi.spyOn(assetPilotApi, 'getUnusedAssets').mockResolvedValue({
      items: [], total: 0, page: 1, pages: 0, hasMore: false, truncated: false,
    })
    const { rerender } = renderHook(
      ({ extension }) => useUnusedAssets({ page: 1, limit: 25, extension }),
      { initialProps: { extension: undefined as string | undefined } },
    )

    expect(request).toHaveBeenCalledOnce()
    rerender({ extension: 'p' })
    rerender({ extension: 'pn' })
    rerender({ extension: 'png' })
    expect(request).toHaveBeenCalledOnce()

    act(() => vi.advanceTimersByTime(SERVER_FILTER_DEBOUNCE_MS))

    expect(request).toHaveBeenCalledTimes(2)
    expect(request).toHaveBeenLastCalledWith(expect.objectContaining({ extension: 'png' }), expect.any(AbortSignal))
  })
})
