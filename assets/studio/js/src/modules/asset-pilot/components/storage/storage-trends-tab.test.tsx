import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import type { StorageTrendResponse } from '../../types'
import { StorageTrendsTab } from './storage-trends-tab'

const mocks = vi.hoisted(() => ({
  refetch: vi.fn(),
}))

const trends: StorageTrendResponse = {
  type: null,
  items: [
    { capturedAt: '2026-07-16 10:00:00', count: 4, size: 1048576, unknownSizeCount: 0 },
    { capturedAt: '2026-07-01 10:00:00', count: 9, size: 3145728, unknownSizeCount: 0 },
  ],
}

vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useStorageTrends: () => ({ data: trends, loading: false, error: null, refetch: mocks.refetch }),
}))

describe('StorageTrendsTab', () => {
  it('renders a negative storage delta with an explicit minus sign', () => {
    renderWithI18n(<StorageTrendsTab />)

    const change = screen.getByText('−2 MiB')
    expect(change).toBeInTheDocument()
    expect(change.textContent).not.toContain('+')
  })
})
