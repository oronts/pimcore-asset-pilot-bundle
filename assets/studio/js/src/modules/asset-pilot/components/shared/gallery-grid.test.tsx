import userEvent from '@testing-library/user-event'
import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { GalleryCards, GalleryGrid, type GalleryCard } from './gallery-grid'

const cards: GalleryCard[] = [
  { key: 1, type: 'image', fallbackLabel: 'locked.png', title: 'Locked asset' },
  { key: 2, selectId: 2, type: 'image', fallbackLabel: 'available.png', title: 'Available asset' },
]

describe('GalleryCards', () => {
  it('renders selection only for cards explicitly marked selectable', () => {
    renderWithI18n(<GalleryCards cards={cards} selection={{ selected: new Set(), toggleSelect: vi.fn() }} />)

    expect(screen.queryByRole('checkbox', { name: 'Select row 1' })).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Select row 2' })).toBeInTheDocument()
  })
})

describe('GalleryGrid', () => {
  it('offers a retry affordance that invokes the supplied callback', async () => {
    const user = userEvent.setup()
    const onRetry = vi.fn()
    renderWithI18n(
      <GalleryGrid
        data={null}
        loading={false}
        error="Could not load assets"
        onRetry={onRetry}
        empty={<p>Empty</p>}
        page={1}
        pages={1}
        onPage={vi.fn()}
        toCard={() => ({ key: 1, type: 'image', fallbackLabel: 'x', title: 'x' })}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(onRetry).toHaveBeenCalledOnce()
  })
})
