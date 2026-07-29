import { test as base, expect, type Page } from '@playwright/test'

export type Role = 'admin' | 'operate' | 'view'

// Per-role credentials for the disjoint-workspace users (acceptance matrix rows 4-7). Seeded into the
// test-project / CI Pimcore before the run; never hard-coded.
function credentials(role: Role): { user: string; pass: string } {
  const key = role.toUpperCase()
  const user = process.env[`E2E_${key}_USER`]
  const pass = process.env[`E2E_${key}_PASS`]
  if (!user || !pass) {
    throw new Error(`Missing E2E_${key}_USER / E2E_${key}_PASS for the "${role}" role (see docs/e2e-acceptance.md).`)
  }
  return { user, pass }
}

// The Pimcore 12 admin login uses placeholder-only inputs (no <label>) and a hidden csrfToken the browser
// submits with the form. Validated live against Pimcore 12.3.
export async function loginAs(page: Page, role: Role): Promise<void> {
  const { user, pass } = credentials(role)
  await page.goto('/admin/login')
  await page.getByPlaceholder(/username/i).fill(user)
  await page.getByPlaceholder(/password/i).fill(pass)
  await page.getByRole('button', { name: /login/i }).click()
  // /admin/login also matches the shell URL, so exclude it: a rejected login must not read as authenticated.
  // The explicit timeout (below the test timeout) makes a rejected login reject here instead of hanging.
  await page.waitForURL(
    (url) => /^\/(admin|pimcore-studio)(\/|$)/.test(url.pathname) && !url.pathname.startsWith('/admin/login'),
    { timeout: 15_000 },
  )
  await expect(page.getByRole('button', { name: /login/i })).toHaveCount(0)
}

// Opens the Asset Pilot module; the nav group is clicked (not hovered) so its items stay for a real click.
export async function openAssetPilot(page: Page): Promise<void> {
  await page.goto('/pimcore-studio/')
  await page.getByTestId('main-nav-trigger').click()
  await page.getByTestId('nav-button-experienceecommerce').click()
  await page.getByTestId('nav-button-experienceecommerce-asset-pilot').click()
  await expect(page.getByRole('tab', { name: 'Dashboard' })).toBeVisible()
}

export const test = base
export { expect }
