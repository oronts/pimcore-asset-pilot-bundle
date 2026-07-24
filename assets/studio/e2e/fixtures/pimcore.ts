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
  await page.waitForURL(/\/(admin|pimcore-studio)(\/|$)/)
}

// Opens the Asset Pilot Studio module. The bundle registers a Module Federation remote in the
// Pimcore Studio shell at /pimcore-studio/ (NOT the classic /admin ExtJS shell), under
// Experience & E-commerce -> Asset Pilot.
//
// SCAFFOLDING: loginAs + the Studio shell boot are live-validated (Pimcore 12.3), but the module launcher is
// an icon-only widget bar with no accessible name, so these role/name selectors cannot target it yet (it needs
// a stable data-test attribute or a deep-link). The View-user gating is meanwhile proven at the API layer.
export async function openAssetPilot(page: Page): Promise<void> {
  await page.goto('/pimcore-studio/')
  await page.getByRole('button', { name: /experience & e-commerce/i }).click()
  await page.getByRole('menuitem', { name: /asset pilot/i }).click()
}

export const test = base
export { expect }
