import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useRules: () => ({
    data: [{ name: 'product-assets', class: 'Product' }],
    loading: false,
    error: null,
    refetch: vi.fn(),
  }),
  useDrift: () => ({ data: null, loading: false, error: null }),
}))
vi.mock('../shared/open-button', () => ({
  OpenButton: ({ id }: { id: number }) => <span>#{id}</span>,
}))
vi.mock('../shared/gallery-grid', () => ({
  GalleryCards: () => null,
  ViewToggle: () => null,
}))

import { renderWithI18n } from '../../../../../test/render'
import { DriftTab } from './drift-tab'

describe('DriftTab', () => {
  it('names the class selector', () => {
    renderWithI18n(<DriftTab />)

    expect(screen.getByRole('combobox', { name: 'Select a class...' })).toBeInTheDocument()
  })
})
