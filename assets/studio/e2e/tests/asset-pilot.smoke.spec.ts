import AxeBuilder from '@axe-core/playwright'
import { test, expect, loginAs, openAssetPilot } from '../fixtures/pimcore'

// A representative slice of the acceptance matrix (docs/e2e-acceptance.md). Extend per row: this seed
// covers admin dashboard + a11y (rows 8, 10) and View-user tab gating (row 5).

test.describe('Asset Pilot Studio acceptance', () => {
  test('admin opens the dashboard and it has no serious a11y violations (rows 8, 10)', async ({ page }) => {
    await loginAs(page, 'admin')
    await openAssetPilot(page)

    await expect(page.getByRole('tab', { name: 'Dashboard' })).toBeVisible()

    const results = await new AxeBuilder({ page }).analyze()
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([])
  })

  test('a View user cannot reach the admin-only Storage tab (row 5)', async ({ page }) => {
    await loginAs(page, 'view')
    await openAssetPilot(page)

    await expect(page.getByRole('tab', { name: 'Storage' })).toHaveCount(0)
  })
})
