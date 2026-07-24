import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@pimcore/studio-ui-bundle/api', () => ({
  getPrefix: () => '/pimcore-studio/api',
}))

import { ApiError, assetPilotApi } from '../../services/api'
import type { DuplicateGroup, MergeResult } from '../../types'
import { renderWithI18n } from '../../../../../test/render'
import { MergeModal } from './merge-modal'

vi.mock('../operations/operation-run-panel', () => ({
  OperationRunPanel: ({ runId }: { runId: string }) => <div data-testid="operation-run">{runId}</div>,
}))

const group: DuplicateGroup = {
  checksum: 'abc123',
  fileSize: 100,
  count: 2,
  assetIds: [7, 4],
  representative: null,
}

const preview: MergeResult = {
  checksum: 'abc123',
  canonicalId: 4,
  dryRun: true,
  planToken: 'signed-plan',
  runId: null,
  status: null,
  statusUrl: null,
  dispositions: [{ copyId: 7, outcome: 'quarantined', reason: 'safe' }],
}

function renderModal() {
  const onMerged = vi.fn()
  const onClose = vi.fn()
  return {
    onClose,
    onMerged,
    ...renderWithI18n(
      <MergeModal
        group={group}
        strategies={{ default: 'quarantine', strategies: ['quarantine', 'delete'] }}
        strategiesLoading={false}
        strategiesError={null}
        canApply
        onClose={onClose}
        onMerged={onMerged}
      />,
    ),
  }
}

describe('MergeModal', () => {
  it('applies exactly the token returned by the current preview', async () => {
    const user = userEvent.setup()
    const merge = vi.spyOn(assetPilotApi, 'mergeDuplicates')
      .mockResolvedValueOnce(preview)
      .mockResolvedValueOnce({ ...preview, dryRun: false, planToken: null, runId: 'a'.repeat(32), status: 'completed', statusUrl: '/operations/runs/' + 'a'.repeat(32) })
    const { onMerged } = renderModal()

    const apply = screen.getByRole('button', { name: 'Merge' })
    expect(apply).toBeDisabled()

    await user.click(screen.getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(apply).toBeEnabled())
    expect(merge).toHaveBeenNthCalledWith(
      1,
      'abc123',
      4,
      undefined,
      true,
      undefined,
      expect.any(AbortSignal),
    )

    await user.click(apply)
    await waitFor(() => expect(onMerged).toHaveBeenCalledOnce())
    expect(merge).toHaveBeenNthCalledWith(
      2,
      'abc123',
      4,
      undefined,
      false,
      'signed-plan',
      expect.any(AbortSignal),
    )
  })

  it('keeps a queued merge visible and resumes its persisted run', async () => {
    const user = userEvent.setup()
    const runId = 'a'.repeat(32)
    vi.spyOn(assetPilotApi, 'mergeDuplicates')
      .mockResolvedValueOnce(preview)
      .mockResolvedValueOnce({ ...preview, dryRun: false, planToken: null, runId, status: 'queued', statusUrl: `/operations/runs/${runId}` })
    const resume = vi.spyOn(assetPilotApi, 'resumeDuplicateMerge').mockResolvedValue({
      ...preview,
      dryRun: false,
      planToken: null,
      runId,
      status: 'completed',
      statusUrl: `/operations/runs/${runId}`,
    })
    const { onMerged } = renderModal()

    await user.click(screen.getByRole('button', { name: 'Preview' }))
    await user.click(await screen.findByRole('button', { name: 'Merge' }))

    expect(await screen.findByTestId('operation-run')).toHaveTextContent(runId)
    expect(onMerged).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Resume merge' }))

    await waitFor(() => expect(resume).toHaveBeenCalledWith(runId, expect.any(AbortSignal)))
    await waitFor(() => expect(onMerged).toHaveBeenCalledOnce())
  })

  it('invalidates the preview and token when an input changes', async () => {
    const user = userEvent.setup()
    vi.spyOn(assetPilotApi, 'mergeDuplicates').mockResolvedValue(preview)
    renderModal()

    await user.click(screen.getByRole('button', { name: 'Preview' }))
    const apply = screen.getByRole('button', { name: 'Merge' })
    await waitFor(() => expect(apply).toBeEnabled())

    await user.selectOptions(screen.getByRole('combobox', { name: 'Disposition strategy' }), 'delete')

    expect(apply).toBeDisabled()
    expect(screen.queryByText('safe')).not.toBeInTheDocument()
  })

  it('clears a stale preview and surfaces a 409 through the existing alert', async () => {
    const user = userEvent.setup()
    vi.spyOn(assetPilotApi, 'mergeDuplicates')
      .mockResolvedValueOnce(preview)
      .mockRejectedValueOnce(new ApiError('Preview expired', 409))
    renderModal()

    await user.click(screen.getByRole('button', { name: 'Preview' }))
    const apply = screen.getByRole('button', { name: 'Merge' })
    await waitFor(() => expect(apply).toBeEnabled())
    await user.click(apply)

    expect(await screen.findByRole('alert')).toHaveTextContent('Preview expired')
    expect(apply).toBeDisabled()
    expect(screen.queryByText('safe')).not.toBeInTheDocument()
  })

  it('rejects a preview response without a signed plan', async () => {
    vi.spyOn(assetPilotApi, 'mergeDuplicates').mockResolvedValue({ ...preview, planToken: null })
    const user = userEvent.setup()
    renderModal()

    await user.click(screen.getByRole('button', { name: 'Preview' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The server did not return a usable signed preview.')
    expect(screen.getByRole('button', { name: 'Merge' })).toBeDisabled()
  })
})
