import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { expectNoAccessibilityViolations } from '../../../../../test/accessibility'
import { renderWithI18n } from '../../../../../test/render'
import type { HealHistoryResponse } from '../../types'
import { HealHistory } from './heal-history'

const mocks = vi.hoisted(() => ({
  refetch: vi.fn(),
  undoHeal: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

const history: HealHistoryResponse = {
  items: [
    {
      id: 1,
      assetId: 10,
      path: '/Products/current.jpg',
      fromVersion: 4,
      toVersion: 5,
      checker: 'image',
      status: 'healed',
      createdAt: '2026-07-15 10:00:00',
      eligible: true,
      eligibilityReason: null,
      reason: null,
    },
    {
      id: 2,
      assetId: 20,
      path: '/Products/old.jpg',
      fromVersion: 7,
      toVersion: 8,
      checker: 'image',
      status: 'healed',
      createdAt: '2026-07-14 10:00:00',
      eligible: false,
      eligibilityReason: 'superseded',
      reason: 'A newer reversible heal supersedes this record.',
    },
    {
      id: 3,
      assetId: 30,
      path: '/Products/undone.jpg',
      fromVersion: 10,
      toVersion: 11,
      checker: 'image',
      status: 'undone',
      createdAt: '2026-07-13 10:00:00',
      eligible: false,
      eligibilityReason: 'already_undone',
      reason: 'This heal has already been undone.',
    },
  ],
  total: 3,
  page: 1,
  pages: 1,
  hasMore: false,
  truncated: false,
}

vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useHealHistory: () => ({ data: history, loading: false, error: null, refetch: mocks.refetch }),
}))

vi.mock('../../hooks/use-toast', () => ({
  useToast: () => ({ success: mocks.success, error: mocks.error }),
}))

vi.mock('../../services/api', () => ({
  assetPilotApi: { undoHeal: mocks.undoHeal },
}))

vi.mock('../shared/open-button', async () => {
  const react = await import('react')

  return { OpenButton: ({ id }: { id: number }) => react.createElement('span', null, `#${id}`) }
})

describe('HealHistory', () => {
  it('exposes Undo only for records that are currently eligible', async () => {
    const { container } = renderWithI18n(<HealHistory />)

    expect(screen.getAllByRole('button', { name: 'Undo' })).toHaveLength(1)
    expect(screen.getByText('Superseded by a newer heal')).toBeInTheDocument()
    expect(screen.getByText('Already undone')).toBeInTheDocument()
    await expectNoAccessibilityViolations(container)
  })
})
