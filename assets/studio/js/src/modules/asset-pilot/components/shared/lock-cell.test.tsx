import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { assetPilotApi } from '../../services/api'
import { LockCell } from './lock-cell'

let permissions = { view: true, operate: true, admin: false, tagsAssignment: true }
vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => permissions }))

describe('LockCell', () => {
  it('unlocks a locked asset and notifies the parent', async () => {
    permissions = { view: true, operate: true, admin: false, tagsAssignment: true }
    const unlock = vi.spyOn(assetPilotApi, 'unlockAsset').mockResolvedValue({ message: 'ok', assetId: 7 })
    const onUnlocked = vi.fn()
    renderWithI18n(<LockCell id={7} onUnlocked={onUnlocked} />)

    fireEvent.click(screen.getByRole('button', { name: 'Unlock' }))

    await waitFor(() => expect(unlock).toHaveBeenCalledWith(7))
    await waitFor(() => expect(onUnlocked).toHaveBeenCalled())
  })

  it('hides the unlock control from a viewer without operate', () => {
    permissions = { view: true, operate: false, admin: false, tagsAssignment: false }
    renderWithI18n(<LockCell id={7} onUnlocked={vi.fn()} />)

    expect(screen.queryByRole('button', { name: 'Unlock' })).not.toBeInTheDocument()
  })
})
