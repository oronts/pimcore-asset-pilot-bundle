import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, it, vi } from 'vitest'
import { EmptyFoldersTab } from '../../src/modules/asset-pilot/components/folders/empty-folders-tab'
import { assetPilotApi } from '../../src/modules/asset-pilot/services/api'
import { expectNoAccessibilityViolations } from '../accessibility'
import { renderWithI18n } from '../render'

vi.mock('../../src/modules/asset-pilot/hooks/use-asset-pilot-api', () => ({
  useEmptyFolders: () => ({
    data: { items: [{ id: 7, path: '/empty/' }], page: 1, limit: 50, hasMore: false },
    loading: false,
    error: null,
    refetch: vi.fn(),
  }),
}))
vi.mock('../../src/modules/asset-pilot/hooks/use-permissions', () => ({
  usePermissions: () => ({ view: true, operate: true, admin: false }),
}))
vi.mock('../../src/modules/asset-pilot/hooks/use-toast', () => ({
  useToast: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))
vi.mock('../../src/modules/asset-pilot/components/shared/open-button', () => ({
  OpenButton: ({ id }: { id: number }) => <span>#{id}</span>,
}))

describe('empty-folder deletion accessibility', () => {
  afterEach(() => vi.restoreAllMocks())

  it('has no detectable violations for a reviewed deletion', async () => {
    vi.spyOn(assetPilotApi, 'previewDeleteEmptyFolders').mockResolvedValue({
      deleted: 0,
      eligible: 1,
      skipped: 0,
      failed: 0,
      errors: {},
      dryRun: true,
      planToken: 'folder-plan',
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(<EmptyFoldersTab />)

    await user.click(screen.getByRole('checkbox', { name: 'Select folder /empty/' }))
    await user.click(screen.getByRole('button', { name: 'Review selected (1)' }))
    await screen.findByRole('alertdialog', { name: 'Delete empty folders' })

    await expectNoAccessibilityViolations(container)
  })
})
