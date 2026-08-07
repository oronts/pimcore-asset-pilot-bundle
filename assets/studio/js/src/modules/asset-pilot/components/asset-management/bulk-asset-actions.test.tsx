import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { assetPilotApi } from '../../services/api'
import { ToastProvider } from '../shared/toast/toast-context'
import { BulkAssetActions } from './bulk-asset-actions'

let permissions = { view: true, operate: true, admin: false, tagsAssignment: true }
vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => permissions }))

const renderBar = (assetIds: number[] = [1, 2], lockedIds: number[] = []): void => {
  renderWithI18n(
    <ToastProvider>
      <BulkAssetActions assetIds={assetIds} lockedIds={lockedIds} onResult={vi.fn()} onDeselect={vi.fn()} />
    </ToastProvider>,
  )
}

describe('BulkAssetActions tag gating', () => {
  it('shows the assign-tags action when the operator also holds tags_assignment', () => {
    permissions = { view: true, operate: true, admin: false, tagsAssignment: true }
    renderBar()

    expect(screen.getByRole('button', { name: 'Assign Tags' })).toBeInTheDocument()
  })

  it('hides the assign-tags action from an operator without tags_assignment but keeps other actions', () => {
    permissions = { view: true, operate: true, admin: false, tagsAssignment: false }
    renderBar()

    expect(screen.queryByRole('button', { name: 'Assign Tags' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Set Property' })).toBeInTheDocument()
  })
})

describe('BulkAssetActions protection-aware eligibility', () => {
  it('warns about protected assets and keeps metadata edits available for the unlocked ones', () => {
    permissions = { view: true, operate: true, admin: false, tagsAssignment: true }
    renderBar([1, 2, 3], [2, 3])

    expect(screen.getByText('2 protected asset(s) will be skipped for edits')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Set Property' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Lock Selected' })).toBeEnabled()
  })

  it('disables metadata edits and locking when every selected asset is protected, but unlock stays live', () => {
    permissions = { view: true, operate: true, admin: false, tagsAssignment: true }
    renderBar([1, 2], [1, 2])

    expect(screen.getByRole('button', { name: 'Set Property' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Assign Tags' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Lock Selected' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Unlock Selected' })).toBeEnabled()
  })

  it('unlocks every selected asset but locks only the unlocked ones', async () => {
    permissions = { view: true, operate: true, admin: false, tagsAssignment: true }
    const lock = vi.spyOn(assetPilotApi, 'lockAsset').mockResolvedValue(undefined as never)
    const unlock = vi.spyOn(assetPilotApi, 'unlockAsset').mockResolvedValue(undefined as never)
    const user = userEvent.setup()
    renderBar([1, 2, 3], [2, 3])

    await user.click(screen.getByRole('button', { name: 'Unlock Selected' }))
    await waitFor(() => expect(unlock).toHaveBeenCalledTimes(3))
    expect(unlock.mock.calls.map(c => c[0]).sort()).toEqual([1, 2, 3])

    await user.click(screen.getByRole('button', { name: 'Lock Selected' }))
    await waitFor(() => expect(lock).toHaveBeenCalledTimes(1))
    expect(lock).toHaveBeenCalledWith(1)

    lock.mockRestore()
    unlock.mockRestore()
  })
})
