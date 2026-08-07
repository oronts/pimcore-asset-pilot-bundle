import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { DriftResponse } from '../../types'

let driftResult: { data: DriftResponse | null; loading: boolean; error: string | null } = { data: null, loading: false, error: null }

vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useRules: () => ({
    data: [{ name: 'product-assets', class: 'Product' }],
    loading: false,
    error: null,
    refetch: vi.fn(),
  }),
  useDrift: () => driftResult,
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
    driftResult = { data: null, loading: false, error: null }
    renderWithI18n(<DriftTab />)

    expect(screen.getByRole('combobox', { name: 'Select a class...' })).toBeInTheDocument()
  })

  it('does not claim "No drift" when a zero-result scan was truncated', async () => {
    driftResult = { data: { items: [], objectsScanned: 0, page: 1, limit: 50, truncated: true }, loading: false, error: null }
    renderWithI18n(<DriftTab />)

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Select a class...' }), 'Product')

    expect(screen.getByText(/candidate budget/i)).toBeInTheDocument()
    expect(screen.queryByText('No drift')).not.toBeInTheDocument()
  })
})
