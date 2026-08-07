import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import type { Cart } from '../../hooks/use-cart'
import { AssetCartBar } from './asset-cart-bar'

vi.mock('../shared/open-button', () => ({
  OpenButton: ({ id, label }: { id: number; label?: string }) => <button>{label ?? id}</button>,
}))

const createCart = (): Cart => ({
  ids: [12, 19],
  count: 2,
  has: vi.fn(),
  add: vi.fn(),
  remove: vi.fn(),
  clear: vi.fn(),
})

describe('AssetCartBar', () => {
  it('lists every cart asset as inspectable and individually removable', async () => {
    const cart = createCart()
    const user = userEvent.setup()
    renderWithI18n(<AssetCartBar cart={cart} />)

    await user.click(screen.getByText('Cart items'))

    expect(screen.getByRole('button', { name: '#12' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '#19' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Remove asset #12 from cart' }))

    expect(cart.remove).toHaveBeenCalledWith(12)
  })
})
