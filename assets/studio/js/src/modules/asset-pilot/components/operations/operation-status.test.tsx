import { screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { assetPilotApi } from '../../services/api'
import { OperationStatus } from './operation-status'

vi.mock('../shared/open-button', () => ({ OpenButton: ({ id }: { id: number }) => <span>Asset #{id}</span> }))

afterEach(() => {
  vi.restoreAllMocks()
})

describe('OperationStatus', () => {
  it('localizes the completed-with-observer-error status stat', async () => {
    vi.spyOn(assetPilotApi, 'getStatus').mockResolvedValue({
      stats: { completed: 3, completed_with_observer_error: 1 },
      recentOperations: [],
    })

    renderWithI18n(<OperationStatus />)

    await waitFor(() => {
      expect(screen.getByText(/Completed with observer error/)).toBeInTheDocument()
    })
  })
})
