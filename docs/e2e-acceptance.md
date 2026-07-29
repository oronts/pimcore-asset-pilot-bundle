[← Documentation index](index.md) · [Project README](../README.md)

# End-to-end release acceptance

The PHPUnit suite is intentionally kernel-free and the Studio tests run in jsdom, so neither boots a
real Pimcore, a real Messenger consumer, a real browser, or multiple users. This page defines the
deployment-level acceptance matrix a release should pass on top of those unit gates, and points at the
browser specs that help exercise it.

> `.github/workflows/e2e.yml` now runs real steps rather than `TODO(operator)` echo stubs: it installs a
> Pimcore skeleton with the bundle, registers and installs Studio + Asset Pilot, migrates, seeds the
> Operate and View users on disjoint asset workspaces (`.github/e2e/SeedAcceptanceUsersCommand.php`, run as
> `app:asset-pilot:seed-acceptance-users`), builds the Studio remote, starts both Messenger consumers and
> waits for the health probe to go green, then runs the Playwright + axe suite. It is NOT yet a validated
> gate: it stays on `workflow_dispatch` until it has passed once on the target runner, because a full
> skeleton install has environment-specific details (chiefly the Studio security/firewall configuration
> applied by the Studio bundles' Flex recipes, and the installer secrets) that only a first live run
> confirms. Promote it to a required gate (add `push`/`pull_request` triggers plus branch protection) after
> that first green run. One live-validation item remains inside the browser specs: the Pimcore Studio
> module launcher has no stable accessible name, so `openAssetPilot()` still uses role/name selectors; the
> role authorization it exercises is already proven at the API layer.

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
| 8 | Flows | the central Studio flows load and act: dashboard, rules (list/detail/preview/diff/export), audit + revert, duplicates + merge, integrity + heal, unused + quarantine, empty folders (scan/cleanup), asset management, drift, operations status |
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
