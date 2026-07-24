import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { OperationRecoveryPanel } from '../../src/modules/asset-pilot/components/operations/operation-recovery-panel'
import { assetPilotApi } from '../../src/modules/asset-pilot/services/api'
import { expectNoAccessibilityViolations } from '../accessibility'
import { renderWithI18n } from '../render'

vi.mock('../../src/modules/asset-pilot/hooks/use-permissions', () => ({
  usePermissions: () => ({ view: true, operate: true, admin: true }),
}))
vi.mock('../../src/modules/asset-pilot/components/shared/open-button', () => ({
  OpenButton: ({ id }: { id: number }) => <span>Asset #{id}</span>,
}))

describe('operation recovery accessibility', () => {
  it('has no detectable violations for an unresolved applied result', async () => {
    vi.spyOn(assetPilotApi, 'previewOperationRecovery').mockResolvedValue({
      applied: false,
      planToken: 'signed-recovery-plan',
      unresolved: 1,
      results: [{
        operationId: 91,
        assetId: 7,
        kind: 'move',
        classification: 'recovery_required',
        journalUpdated: false,
        message: 'The asset is unavailable.',
      }],
    })
    vi.spyOn(assetPilotApi, 'applyOperationRecovery').mockResolvedValue({
      applied: true,
      planToken: null,
      unresolved: 1,
      results: [{
        operationId: 91,
        assetId: 7,
        kind: 'move',
        classification: 'recovery_required',
        journalUpdated: false,
        message: 'The asset is unavailable.',
      }],
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(<OperationRecoveryPanel />)

    await user.click(screen.getByRole('button', { name: 'Review recoverable operations' }))
    await user.click(await screen.findByRole('button', { name: 'Apply reviewed recovery' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Apply reviewed recovery' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('still require attention')
    await expectNoAccessibilityViolations(container)
  })
})
