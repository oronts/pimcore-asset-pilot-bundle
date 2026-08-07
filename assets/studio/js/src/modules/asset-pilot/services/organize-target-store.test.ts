import { afterEach, describe, expect, it, vi } from 'vitest'
import { organizeTargetStore } from './organize-target-store'

describe('organizeTargetStore', () => {
  afterEach(() => {
    // Clear any folder a test left pending so the module-level singleton stays isolated between tests.
    organizeTargetStore.consume()
  })

  it('records a requested folder and consumes it exactly once', () => {
    organizeTargetStore.request('/Products')

    expect(organizeTargetStore.consume()).toBe('/Products')
    expect(organizeTargetStore.consume()).toBeNull()
  })

  it('returns null when nothing is pending', () => {
    expect(organizeTargetStore.consume()).toBeNull()
  })

  it('peeks the pending folder without clearing it', () => {
    organizeTargetStore.request('/Products')

    expect(organizeTargetStore.peek()).toBe('/Products')
    expect(organizeTargetStore.peek()).toBe('/Products')
    expect(organizeTargetStore.consume()).toBe('/Products')
    expect(organizeTargetStore.peek()).toBeNull()
  })

  it('keeps only the latest requested folder', () => {
    organizeTargetStore.request('/first')
    organizeTargetStore.request('/second')

    expect(organizeTargetStore.consume()).toBe('/second')
  })

  it('notifies subscribers on request and stops after unsubscribe', () => {
    const listener = vi.fn()
    const unsubscribe = organizeTargetStore.subscribe(listener)

    organizeTargetStore.request('/A')
    expect(listener).toHaveBeenCalledTimes(1)

    unsubscribe()
    organizeTargetStore.request('/B')
    expect(listener).toHaveBeenCalledTimes(1)
  })
})
