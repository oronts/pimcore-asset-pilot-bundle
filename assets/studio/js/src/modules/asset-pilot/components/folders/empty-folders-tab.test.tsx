import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithI18n } from '../../../../../test/render'
import { ApiError, assetPilotApi } from '../../services/api'
import type { EmptyFolderDeleteResult } from '../../types'
import { EmptyFoldersTab } from './empty-folders-tab'

const mocks = vi.hoisted(() => ({
  refetch: vi.fn(),
  success: vi.fn(),
  warning: vi.fn(),
  error: vi.fn(),
}))

vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useEmptyFolders: () => ({
    data: {
      items: [{ id: 7, path: '/first/' }, { id: 8, path: '/second/' }],
      page: 1,
      limit: 50,
      hasMore: true,
    },
    loading: false,
    error: null,
    refetch: mocks.refetch,
  }),
}))
vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => ({ view: true, operate: true, admin: false }) }))
vi.mock('../../hooks/use-toast', () => ({ useToast: () => ({ success: mocks.success, warning: mocks.warning, error: mocks.error }) }))
vi.mock('../shared/open-button', () => ({ OpenButton: ({ id }: { id: number }) => <span>#{id}</span> }))

function preview(overrides: Partial<EmptyFolderDeleteResult> = {}): EmptyFolderDeleteResult {
  return {
    deleted: 0,
    eligible: 1,
    skipped: 0,
    failed: 1,
    errors: { 8: 'Not permitted to delete this folder' },
    dryRun: true,
    planToken: 'folder-plan',
    ...overrides,
  }
}

async function selectBoth(user: ReturnType<typeof userEvent.setup>): Promise<void> {
  await user.click(screen.getByRole('checkbox', { name: 'Select folder /first/' }))
  await user.click(screen.getByRole('checkbox', { name: 'Select folder /second/' }))
}

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

describe('EmptyFoldersTab immutable deletion plans', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    mocks.refetch.mockReset()
    mocks.success.mockReset()
    mocks.warning.mockReset()
    mocks.error.mockReset()
  })

  it('re-enables review when the selection changes during an in-flight preview', async () => {
    const pending = deferred<EmptyFolderDeleteResult>()
    vi.spyOn(assetPilotApi, 'previewDeleteEmptyFolders').mockReturnValue(pending.promise)
    const user = userEvent.setup()
    renderWithI18n(<EmptyFoldersTab />)

    await selectBoth(user)
    await user.click(screen.getByRole('button', { name: 'Review selected (2)' }))
    expect(screen.getByRole('button', { name: 'Review selected (2)' })).toBeDisabled()

    await user.click(screen.getByRole('checkbox', { name: 'Select folder /second/' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review selected (1)' })).toBeEnabled())
  })

  it('rejects a response that is not a signed dry-run plan', async () => {
    vi.spyOn(assetPilotApi, 'previewDeleteEmptyFolders').mockResolvedValue(preview({ dryRun: false }))
    const user = userEvent.setup()
    renderWithI18n(<EmptyFoldersTab />)

    await user.click(screen.getByRole('checkbox', { name: 'Select folder /first/' }))
    await user.click(screen.getByRole('button', { name: 'Review selected (1)' }))

    expect(mocks.error).toHaveBeenCalledWith('The preview did not include a valid plan. Review the folders again.')
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('shows eligibility errors before applying the exact reviewed IDs', async () => {
    vi.spyOn(assetPilotApi, 'previewDeleteEmptyFolders').mockResolvedValue(preview())
    vi.spyOn(assetPilotApi, 'applyDeleteEmptyFolders').mockResolvedValue(preview({
      deleted: 1,
      eligible: undefined,
      dryRun: false,
      planToken: null,
    }))
    const user = userEvent.setup()
    renderWithI18n(<EmptyFoldersTab />)

    await selectBoth(user)
    await user.click(screen.getByRole('button', { name: 'Review selected (2)' }))

    const dialog = await screen.findByRole('alertdialog', { name: 'Delete empty folders' })
    expect(within(dialog).getByText('Eligible: 1, skipped: 0, blocked: 1')).toBeInTheDocument()
    expect(within(dialog).getByText('Folder #8: Not permitted to delete this folder')).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }))

    expect(assetPilotApi.previewDeleteEmptyFolders).toHaveBeenCalledWith([7, 8], expect.any(AbortSignal))
    expect(assetPilotApi.applyDeleteEmptyFolders).toHaveBeenCalledWith([7, 8], 'folder-plan', expect.any(AbortSignal))
    expect(await screen.findByText('Deletion result')).toBeInTheDocument()
    expect(mocks.refetch).toHaveBeenCalledOnce()
  })

  it('invalidates a reviewed deletion when the selection changes', async () => {
    vi.spyOn(assetPilotApi, 'previewDeleteEmptyFolders').mockResolvedValue(preview({ eligible: 2, failed: 0, errors: {} }))
    const user = userEvent.setup()
    renderWithI18n(<EmptyFoldersTab />)

    await selectBoth(user)
    await user.click(screen.getByRole('button', { name: 'Review selected (2)' }))
    expect(await screen.findByRole('alertdialog')).toBeInTheDocument()
    await user.click(screen.getByRole('checkbox', { name: 'Select folder /second/' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
  })

  it('invalidates a reviewed deletion when the page or limit changes', async () => {
    vi.spyOn(assetPilotApi, 'previewDeleteEmptyFolders').mockResolvedValue(preview({ eligible: 1, failed: 0, errors: {} }))
    const user = userEvent.setup()
    renderWithI18n(<EmptyFoldersTab />)

    await user.click(screen.getByRole('checkbox', { name: 'Select folder /first/' }))
    await user.click(screen.getByRole('button', { name: 'Review selected (1)' }))
    expect(await screen.findByRole('alertdialog')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Page 2' }))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: 'Select folder /first/' }))
    await user.click(screen.getByRole('button', { name: 'Review selected (1)' }))
    expect(await screen.findByRole('alertdialog')).toBeInTheDocument()
    await user.selectOptions(screen.getByRole('combobox', { name: 'Rows per page' }), '20')
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('discards a reviewed token after an apply conflict', async () => {
    vi.spyOn(assetPilotApi, 'previewDeleteEmptyFolders').mockResolvedValue(preview({ eligible: 1, failed: 0, errors: {} }))
    vi.spyOn(assetPilotApi, 'applyDeleteEmptyFolders').mockRejectedValue(new ApiError('stale', 409))
    const user = userEvent.setup()
    renderWithI18n(<EmptyFoldersTab />)

    await user.click(screen.getByRole('checkbox', { name: 'Select folder /first/' }))
    await user.click(screen.getByRole('button', { name: 'Review selected (1)' }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }))

    await waitFor(() => expect(mocks.warning).toHaveBeenCalledWith('The selected folders changed after review. Review the deletion again.'))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })
})
