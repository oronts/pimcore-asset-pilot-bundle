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

// SCAFFOLDING: the login selectors below target the Pimcore admin login. Verify them against the
// actual Pimcore version once the runner is wired; they are the one place likely to need adjustment.
export async function loginAs(page: Page, role: Role): Promise<void> {
  const { user, pass } = credentials(role)
  await page.goto('/admin/login')
  await page.getByLabel(/username/i).fill(user)
  await page.getByLabel(/password/i).fill(pass)
  await page.getByRole('button', { name: /login/i }).click()
  await page.waitForURL(/\/admin(\/|$)/)
}

// Opens the Asset Pilot Studio module. The bundle registers a Module Federation remote in the
// Pimcore Studio shell at /pimcore-studio/ (NOT the classic /admin ExtJS shell), under
// Experience & E-commerce -> Asset Pilot. Navigate to the Studio shell and open it from there.
export async function openAssetPilot(page: Page): Promise<void> {
  await page.goto('/pimcore-studio/')
  await page.getByRole('button', { name: /experience & e-commerce/i }).click()
  await page.getByRole('menuitem', { name: /asset pilot/i }).click()
}

export const test = base
export { expect }
