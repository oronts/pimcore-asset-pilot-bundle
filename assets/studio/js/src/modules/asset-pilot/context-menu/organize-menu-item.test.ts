import { describe, expect, it, vi } from 'vitest'
import { buildOrganizeMenuItem } from './organize-menu-item'

describe('buildOrganizeMenuItem', () => {
  it('returns null when the actor cannot operate', () => {
    const item = buildOrganizeMenuItem({ isFolder: true, folder: '/Products', operate: false, label: 'Organize', onOrganize: vi.fn() })

    expect(item).toBeNull()
  })

  it('returns null when the clicked node is not a folder', () => {
    const onOrganize = vi.fn()

    const item = buildOrganizeMenuItem({ isFolder: false, folder: '/Products/logo.png', operate: true, label: 'Organize', onOrganize })

    expect(item).toBeNull()
    expect(onOrganize).not.toHaveBeenCalled()
  })

  it('returns null when the folder path is missing', () => {
    const onOrganize = vi.fn()

    expect(buildOrganizeMenuItem({ isFolder: true, folder: undefined, operate: true, label: 'Organize', onOrganize })).toBeNull()
    expect(buildOrganizeMenuItem({ isFolder: true, folder: '', operate: true, label: 'Organize', onOrganize })).toBeNull()
    expect(onOrganize).not.toHaveBeenCalled()
  })

  it('builds a labeled item whose click organizes the clicked folder', () => {
    const onOrganize = vi.fn()
    const item = buildOrganizeMenuItem({ isFolder: true, folder: '/Products', operate: true, label: 'Organize with Asset Pilot', onOrganize }) as
      | { key: string, label: string, onClick: () => void }
      | null

    expect(item).not.toBeNull()
    expect(item?.key).toBe('asset-pilot-organize')
    expect(item?.label).toBe('Organize with Asset Pilot')

    item?.onClick()
    expect(onOrganize).toHaveBeenCalledWith('/Products')
  })
})
