[← Documentation index](index.md) · [Project README](../README.md)

# End-to-end release acceptance

The PHPUnit suite is intentionally kernel-free and the Studio tests run in jsdom, so neither boots a
real Pimcore, a real Messenger consumer, a real browser, or multiple users. This page defines the
deployment-level acceptance matrix a release should pass on top of those unit gates, and points at the
browser specs that help exercise it.

> The browser specs under `assets/studio/e2e/` are a starting point, not a wired gate: they cover a
> couple of smoke checks today, and running the full matrix needs a host that stands up a real Pimcore,
> MariaDB, the Messenger consumers, three users on disjoint workspaces, and a headless browser. A
> manual scaffold workflow exists at `.github/workflows/e2e.yml` (its Pimcore install/migrate/seed/worker
> steps are stubbed `TODO(operator)`), so it is not a required gate until a host runner is chosen.

## Acceptance matrix

A release is acceptable when every row passes against a freshly installed package on a supported
Pimcore application:

| # | Dimension | What must be exercised |
|---|-----------|------------------------|
| 1 | Install | `composer require oronts/asset-pilot-bundle` into a clean Pimcore skeleton; enable the bundle; deploy `public/studio/build`; `verify-build` passes |
| 2 | Migrate | run bundle migrations on an empty DB and on a legacy fixture DB; `asset-pilot:health` is green |
| 3 | Workers | the `asset_pilot` and `pimcore_maintenance` Messenger consumers are running; a bulk organize delivers durably |
| 4 | Roles | one Admin, one Operate, one View user, on **disjoint** asset workspaces |
| 5 | View | View sees read-only tabs; Admin-only tabs (Storage) are absent and their API returns 403 |
| 6 | Operate | Operate can run organize/heal/quarantine, cannot revert (Admin) and cannot see settings |
| 7 | Admin | Admin can revert, sees every tab, and a revert is audited with the acting user |
| 8 | Flows | the central Studio flows load and act: dashboard, rules (create/preview), audit + revert, duplicates + merge, integrity + heal, unused + quarantine, empty folders (scan/cleanup), asset management, drift, operations status |
| 9 | Timestamps | every timestamp rendered in the UI is the RFC 3339 UTC value, localized correctly |
| 10 | A11y | axe-core reports no serious/critical violations on each central tab |

## Running the browser specs

Point the Playwright specs at a Pimcore application that already has the bundle installed and the
Studio build deployed, with the three users and their disjoint workspaces seeded. The specs read their
credentials from environment variables:

```bash
cd assets/studio/e2e
npm install
npm run install-browsers
E2E_BASE_URL=https://your-pimcore.example \
E2E_ADMIN_USER=... E2E_ADMIN_PASS=... \
E2E_OPERATE_USER=... E2E_OPERATE_PASS=... \
E2E_VIEW_USER=... E2E_VIEW_PASS=... \
npm test
```

## Why this is a separate gate

Rows 3-7 (real worker delivery, actor separation, disjoint workspaces) and rows 8-10 (real browser and
accessibility) cannot be reproduced in a kernel-free unit suite. This matrix is the release contract
that covers them; the unit and Studio suites cover everything below the deployment boundary.
