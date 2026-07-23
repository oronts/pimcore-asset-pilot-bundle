import { defineConfig, devices } from '@playwright/test'

// Deployment-level acceptance (see docs/e2e-acceptance.md). Runs against a live Pimcore that has the
// bundle installed; the base URL and per-role credentials come from the environment so the same specs
// run against the local test-project and against CI.
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1'

export default defineConfig({
  testDir: './tests',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never', outputFolder: '../playwright-report' }]] : 'list',
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
