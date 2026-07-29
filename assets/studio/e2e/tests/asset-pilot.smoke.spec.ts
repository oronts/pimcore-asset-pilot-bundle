import AxeBuilder from '@axe-core/playwright'
import { test, expect, loginAs, openAssetPilot } from '../fixtures/pimcore'

// The twelve central tabs in render order; Storage is Admin-only (asserted absent for a View user below).
const ADMIN_TABS = [
  'Dashboard', 'Rules', 'Operations', 'Audit Log', 'Unused Assets', 'Duplicates',
  'Integrity', 'Quarantine', 'Storage', 'Empty Folders', 'Drift', 'Asset Management',
]

const TABLIST = '[role="tablist"][aria-label="Asset Pilot"]'

async function openTab(page: import('@playwright/test').Page, name: string): Promise<void> {
  const tab = page.getByRole('tab', { name, exact: true })
  await tab.click()
  await expect(tab).toHaveAttribute('aria-selected', 'true')
  const panel = page.locator('#asset-pilot-tabpanel')
  await panel.waitFor({ state: 'visible' })
  // Wait for the tab's data to load so axe scans real content, not skeletons.
  await expect(panel.locator('.ap-skeleton')).toHaveCount(0)
}

test.describe('Asset Pilot Studio acceptance', () => {
  test('a rejected login is not treated as authenticated (guards the M-02 false-success wait)', async ({ page }) => {
    const original = process.env.E2E_VIEW_PASS
    process.env.E2E_VIEW_PASS = 'definitely-wrong-password'
    try {
      await expect(loginAs(page, 'view')).rejects.toThrow()
    } finally {
      process.env.E2E_VIEW_PASS = original
    }
  })

  test('admin: every central tab opens with no serious a11y violation (rows 8, 10)', async ({ page }) => {
    test.setTimeout(120_000)
    await loginAs(page, 'admin')
    await openAssetPilot(page)

    const rendered = (await page.locator(TABLIST).getByRole('tab').allInnerTexts()).map((t) => t.trim())
    expect(rendered).toEqual(ADMIN_TABS)

    for (const name of ADMIN_TABS) {
      await openTab(page, name)
      // Scope axe to all bundle-owned UI (header + tablist + panel); the surrounding Pimcore Studio
      // shell has its own unrelated a11y issues we neither own nor can fix.
      const results = await new AxeBuilder({ page }).include('[data-testid="asset-pilot-root"]').analyze()
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
      expect(serious, `${name} a11y: ` + JSON.stringify(serious.map((v) => ({ id: v.id, nodes: v.nodes.length })))).toEqual([])
    }
  })

  test('a View user cannot reach the admin-only Storage tab (row 5)', async ({ page }) => {
    await loginAs(page, 'view')
    await openAssetPilot(page)

    await expect(page.getByRole('tab', { name: 'Dashboard' })).toBeVisible()
    await expect(page.getByRole('tab', { name: 'Storage', exact: true })).toHaveCount(0)
  })

  const API = '/pimcore-studio/api/asset-pilot'
  const LOCK = `${API}/assets/1/lock`

  test('the Studio API rejects an unauthenticated request (row 6)', async ({ request }) => {
    expect((await request.get(`${API}/operations/runs`)).status()).toBe(401)
  })

  test('a View user is stopped at the permission gate for an Operate action (layer 1, row 6)', async ({ page }) => {
    await loginAs(page, 'view')
    expect((await page.request.get(`${API}/operations/runs`)).status()).toBe(200)
    const op = await page.request.post(LOCK)
    expect(op.status()).toBe(403)
    expect(await op.text()).toContain('asset_pilot_operate')
  })

  test('an Operate user passes the permission gate but is stopped by workspace authorization (layer 2, row 7)', async ({ page }) => {
    await loginAs(page, 'operate')
    expect((await page.request.get(`${API}/operations/runs`)).status()).toBe(200)
    const op = await page.request.post(LOCK)
    expect(op.status()).toBe(403)
    expect(await op.text()).toContain('Asset mutation is not permitted')
  })
})
