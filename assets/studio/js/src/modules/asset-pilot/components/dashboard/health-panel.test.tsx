import { screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { HealthPanel } from './health-panel'

const useHealth = vi.hoisted(() => vi.fn())

vi.mock('../../hooks/use-asset-pilot-api', () => ({ useHealth }))

beforeEach(() => {
  useHealth.mockReset()
  useHealth.mockReturnValue({
    data: {
      status: 'warning',
      checks: [{
        name: 'operation_journal',
        status: 'warning',
        message: 'One operation requires recovery.',
        details: { journal: { recovery_required: 1 }, sample_operation_ids: [91] },
      }],
    },
    loading: false,
    error: null,
    refetch: vi.fn(),
  })
})

describe('HealthPanel', () => {
  it('labels checks and exposes structured operational diagnostics', () => {
    renderWithI18n(<HealthPanel />)

    expect(screen.getByText('Operation journal')).toBeInTheDocument()
    expect(screen.getByText('Technical details')).toBeInTheDocument()
    expect(screen.getByText(/"sample_operation_ids":/)).toBeInTheDocument()
    expect(screen.getByText(/91/)).toBeInTheDocument()
  })

  it('localizes the operation-run backlog check and humanizes an unknown consumer check', () => {
    useHealth.mockReturnValue({
      data: {
        status: 'warning',
        checks: [
          { name: 'operation_run_backlog', status: 'warning', message: 'A run has been queued too long.' },
          { name: 'my_consumer_probe', status: 'ok', message: 'Custom probe healthy.' },
        ],
      },
      loading: false,
      error: null,
      refetch: vi.fn(),
    })

    renderWithI18n(<HealthPanel />)

    expect(screen.getByText('Operation run backlog')).toBeInTheDocument()
    expect(screen.getByText('My consumer probe')).toBeInTheDocument()
  })
})
