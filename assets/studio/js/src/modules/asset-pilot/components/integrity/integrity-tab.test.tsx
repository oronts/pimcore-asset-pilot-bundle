import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const useBrokenAssets = vi.hoisted(() => vi.fn())

vi.mock('../../hooks/use-asset-pilot-api', () => ({ useBrokenAssets }))
vi.mock('../../hooks/use-permissions', () => ({
  usePermissions: () => ({ operate: true, admin: false }),
}))
vi.mock('../shared/open-button', () => ({
  OpenButton: ({ id }: { id: number }) => <span>#{id}</span>,
}))
vi.mock('./heal-history', () => ({ HealHistory: () => null }))

import { renderWithI18n } from '../../../../../test/render'
import { IntegrityTab } from './integrity-tab'

const response = {
  items: [{ id: 7, path: '/incoming/a.jpg', checker: 'render', reason: 'Cannot render' }],
  scanned: 25,
  broken: 1,
  page: 1,
  limit: 25,
  hasNext: true,
}

beforeEach(() => {
  useBrokenAssets.mockReset()
  useBrokenAssets.mockReturnValue({
    data: response,
    loading: false,
    error: null,
    refetch: vi.fn(),
  })
})

describe('IntegrityTab heal review invalidation', () => {
  it('closes the heal review and clears selection when filters change', async () => {
    const user = userEvent.setup()
    renderWithI18n(<IntegrityTab />)

    await user.click(screen.getByRole('checkbox', { name: 'Select asset #7' }))
    await user.click(screen.getByRole('button', { name: 'Heal selected (1)' }))
    expect(screen.getByRole('dialog', { name: 'Heal broken assets' })).toBeInTheDocument()

    await user.type(screen.getByRole('textbox', { name: 'Folder' }), '/archive')

    expect(screen.queryByRole('dialog', { name: 'Heal broken assets' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Heal selected (0)' })).toBeDisabled()
    expect(useBrokenAssets).toHaveBeenLastCalledWith(1, 25, { folder: '/archive' })
  })

  it('closes the heal review and clears selection when the page changes', async () => {
    const user = userEvent.setup()
    renderWithI18n(<IntegrityTab />)

    await user.click(screen.getByRole('checkbox', { name: 'Select asset #7' }))
    await user.click(screen.getByRole('button', { name: 'Heal selected (1)' }))
    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(screen.queryByRole('dialog', { name: 'Heal broken assets' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Heal selected (0)' })).toBeDisabled()
    await waitFor(() => expect(useBrokenAssets).toHaveBeenLastCalledWith(2, 25, {}))
  })

  it('keeps pagination visible when a scanned page has no broken rows but more assets exist', () => {
    useBrokenAssets.mockReturnValue({
      data: { ...response, items: [], scanned: 0, broken: 0, hasNext: true },
      loading: false,
      error: null,
      refetch: vi.fn(),
    })

    renderWithI18n(<IntegrityTab />)

    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled()
    expect(screen.queryByText('No broken assets detected')).not.toBeInTheDocument()
  })
})
