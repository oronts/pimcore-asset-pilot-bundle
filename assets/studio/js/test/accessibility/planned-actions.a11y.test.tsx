import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, it, vi } from 'vitest'
import { OrganizeForm } from '../../src/modules/asset-pilot/components/operations/organize-form'
import { BulkActionBar } from '../../src/modules/asset-pilot/components/unused-assets/bulk-action-bar'
import { PropertyForm } from '../../src/modules/asset-pilot/components/asset-management/property-form'
import { TagPicker } from '../../src/modules/asset-pilot/components/asset-management/tag-picker'
import { HealModal } from '../../src/modules/asset-pilot/components/integrity/heal-modal'
import { assetPilotApi } from '../../src/modules/asset-pilot/services/api'
import { expectNoAccessibilityViolations } from '../accessibility'
import { renderWithI18n } from '../render'

vi.mock('../../src/modules/asset-pilot/hooks/use-permissions', () => ({ usePermissions: () => ({ operate: true }) }))
vi.mock('../../src/modules/asset-pilot/hooks/use-toast', () => ({
  useToast: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))
vi.mock('../../src/modules/asset-pilot/components/shared/open-button', () => ({
  OpenButton: ({ id }: { id: number }) => <span>{id}</span>,
}))
vi.mock('../../src/modules/asset-pilot/components/operations/operation-run-panel', () => ({
  OperationRunPanel: () => null,
}))
vi.mock('../../src/modules/asset-pilot/hooks/use-asset-pilot-api', () => ({
  useTags: () => ({
    data: { items: [{ id: 3, name: 'Campaign', parentId: null, path: '/Campaign' }], total: 1, page: 1, limit: 50, pages: 1 },
    loading: false,
    error: null,
    refetch: vi.fn(),
  }),
}))

describe('planned mutation accessibility', () => {
  it('has no detectable violations for a reviewed organize plan', async () => {
    vi.spyOn(assetPilotApi, 'organize').mockResolvedValue({
      dryRun: true,
      planToken: 'single-plan',
      operations: [{
        assetId: 7,
        objectId: 42,
        sourcePath: '/incoming/a.jpg',
        targetPath: '/products/42/a.jpg',
        ruleName: 'product-assets',
        status: 'pending',
      }],
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(<OrganizeForm />)

    await user.type(screen.getByRole('spinbutton', { name: 'Object ID' }), '42')
    await user.click(screen.getByRole('button', { name: 'Preview' }))
    await screen.findByRole('button', { name: 'Apply Reviewed Plan' })

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations for eligibility details in a destructive confirmation', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkDeleteAssets').mockResolvedValue({
      deleted: 0,
      failed: 1,
      errors: { 8: 'Asset is locked' },
      observerWarnings: [],
      dryRun: true,
      planToken: 'delete-plan',
      eligible: 1,
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(
      <BulkActionBar count={2} assetIds={[7, 8]} onActionComplete={vi.fn()} onDeselect={vi.fn()} />,
    )

    await user.click(screen.getByRole('button', { name: 'Review Deletion' }))
    await screen.findByRole('alertdialog', { name: 'Confirm Deletion' })

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations for a reviewed tag metadata plan', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkTagAssets').mockResolvedValue({
      tagged: 0,
      failed: 1,
      errors: { 8: 'Asset is locked' },
      observerWarnings: [],
      dryRun: true,
      planToken: 'tag-plan',
      eligible: 1,
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(<TagPicker assetIds={[7, 8]} onDone={vi.fn()} onCancel={vi.fn()} />)

    await user.click(screen.getByRole('checkbox', { name: '/Campaign' }))
    await user.click(screen.getByRole('button', { name: 'Review Tag Assignment' }))
    await screen.findByRole('button', { name: 'Apply Reviewed Tags' })

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations for a reviewed property metadata plan', async () => {
    vi.spyOn(assetPilotApi, 'previewBulkSetProperty').mockResolvedValue({
      updated: 0,
      failed: 0,
      errors: {},
      observerWarnings: [],
      dryRun: true,
      planToken: 'property-plan',
      eligible: 1,
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(<PropertyForm assetIds={[7]} onDone={vi.fn()} onCancel={vi.fn()} />)

    await user.type(screen.getByRole('textbox', { name: 'Property Name' }), 'source')
    await user.type(screen.getByRole('textbox', { name: 'Value' }), 'catalog')
    await user.click(screen.getByRole('button', { name: 'Review Property Change' }))
    await screen.findByRole('button', { name: 'Apply Reviewed Property' })

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations for a reviewed integrity heal plan', async () => {
    vi.spyOn(assetPilotApi, 'previewHealAssets').mockResolvedValue({
      dryRun: true,
      planToken: 'heal-plan',
      results: [{
        assetId: 7,
        outcome: 'healed',
        toVersion: 4,
        checker: 'render',
        reason: 'Version 5 is corrupt',
        observerWarnings: [],
      }],
    })
    const user = userEvent.setup()
    const { container } = renderWithI18n(
      <HealModal ids={[7]} canApply onClose={vi.fn()} onHealed={vi.fn()} />,
    )

    await user.click(screen.getByRole('button', { name: 'Review heal plan' }))
    await screen.findByRole('button', { name: 'Apply reviewed heal' })

    await expectNoAccessibilityViolations(container)
  })
})
