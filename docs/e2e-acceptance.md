[← Documentation index](index.md) · [Project README](../README.md)

# End-to-end release acceptance

The PHPUnit suite is intentionally kernel-free and the Studio tests run in jsdom, so neither boots a
real Pimcore, a real Messenger consumer, a real browser, or multiple users. This page defines the
deployment-level acceptance matrix a release should pass on top of those unit gates, and points at the
browser specs that help exercise it.

## Live acceptance run — 2026-08-04

Server-side and durable-pipeline acceptance against the reference test-project (Pimcore 12.3.11,
PHP 8.4, MariaDB), commit `507932d`, Studio generation `2.0.0-04b21628-de90-44ea-9a8a-fcec3d02c722`:

- All ten Docker services up; Doctrine migrations at the latest version (fifteen owned tables incl.
  `asset_pilot_automatic_organize_intent`); `assets:install` deployed the rebuilt remote.
- `asset-pilot:health` reports OK on every check with both supervised consumers (`asset_pilot`,
  `pimcore_maintenance`) heartbeating, `async_transport` OK, `shared_cache` cross-worker visibility
  proven, and `operation_run_backlog` clear; `asset-pilot:validate-config` passes 28/0/0.
- Durable coalescing lifecycle proven end to end on the automatic producer path: a real data-object save
  recorded one `pending_dispatch` run plus a bound intent; a second rapid save of the same object
  coalesced into that intent (`dirty = 1`) and created no second run (run count delta 1, not 2); the
  maintenance relay published it, a worker organized it, and the terminal drain released and rotated the
  coalesced save until the intent table converged to zero with every run terminal and no leak.
- Real mutation proven: fresh unorganized fixtures (`app:asset-pilot:e2e setup`) had their assets at
  `/AssetPilotE2E/source/...`; a signed-token async `--apply` (run `e1324b3c...`, status `completed`,
  1/1 succeeded) moved both assets to their rule targets under `/AssetPilotE2E/organized/images` and
  `/organized/print`, with `asset_pilot_audit_log` recording the from/to paths and rule; fixtures cleaned.
- Authorization enforced live: an unauthenticated `GET /pimcore-studio/api/asset-pilot/rules` returns
  `401`; the REST controllers carry 58 `#[IsGranted(AssetPilotPermission::*)]` guards.
- The rebuilt Studio remote is served: `assets:install` deployed it and
  `/bundles/orontsassetpilot/studio/build/active.json` reports buildId `2.0.0-04b21628-...` with its
  `remoteEntry.js` returning `HTTP 200` (323 KB).
- CLI flow matrix exercised (preview/read-only): organize (preview + signed-token async apply),
  normalize-filenames, sweep-empty-folders, find-duplicates, verify-locations, check-integrity,
  cleanup-unused (filter guard), and rule-overlap all functional against live fixture data.

The browser Playwright + axe slice is not re-run here (`@playwright/test` is not a local dependency of
`assets/studio`; the CI `e2e.yml` installs it at run time). The current generation is deployed and ready;
run the Playwright suite against `2.0.0-04b21628-...` and record its result before describing this commit
as browser-accepted.

> `.github/workflows/e2e.yml` runs real steps rather than `TODO(operator)` echo stubs: it installs a
> Pimcore skeleton with the bundle, registers and installs Studio + Asset Pilot, migrates, seeds the
> Operate and View users on disjoint asset workspaces (`.github/e2e/SeedAcceptanceUsersCommand.php`, run as
> `app:asset-pilot:seed-acceptance-users`), builds the Studio remote, starts both Messenger consumers and
> waits for the health probe to go green, then runs the Playwright + axe suite.
>
> The browser suite is a representative slice, validated 6/6 end to end against a live Pimcore 12.3 (the
> reference test-project) on 2026-07-29 against generation `2.0.0-be64696b-97b8-475b-aca8-22575d5d3b72`
> (the reviewed state at that time). That is a historical baseline, not the current release candidate:
> re-run the suite against the exact current generation and record its full build ID and date before
> describing this commit as browser-accepted. The slice covers the login-rejection guard, role gating (a View user cannot see the Admin-only
> Storage tab), the two-layer authorization matrix (unauthenticated → `401`; a View user stopped at the
> permission gate; an Operate user stopped at element/workspace authorization), and zero serious axe
> violations across all twelve central tabs. The a11y test asserts the rendered tab set equals the tab
> contract, so a new source tab cannot be silently dropped from coverage. `openAssetPilot()` opens the
> module through the deterministic `data-testid` Pimcore Studio derives from each main-nav item
> (`main-nav-trigger` → `nav-button-experienceecommerce` → `nav-button-experienceecommerce-asset-pilot`),
> a real click on the real-user path. The dashboard root carries a `data-testid="asset-pilot-root"` so axe
> scopes to bundle-owned UI (header, tabs, panel) and excludes the surrounding Pimcore shell.
>
> At the browser layer this slice fully covers row 10 (axe on every central tab) and gives partial
> permission-layer evidence toward rows 5-7: tab-visibility gating (a View user cannot see Storage) and
> the two-layer authorization matrix on one Operate action. It does NOT execute the full role acceptance
> flows those rows require, namely the View Storage API `403`, an Operate organize/heal/quarantine
> execute-and-deny-revert, or an Admin revert with actor-attributed audit. Row 8 is partial: every central
> tab loads, but the per-flow interactions ("load and act") are covered by the unit and live-stack
> evidence, not the browser suite. Real durable mutations, RFC 3339 timestamp localization, and the full
> run/recovery flows remain browser-unautomated. The workflow stays on `workflow_dispatch` until
> the full skeleton install has run green once on the target runner (that install has environment-specific
> details: the Studio security/firewall Flex recipes and the installer secrets). Promote it to a required
> gate (add `push`/`pull_request` triggers plus branch protection) after that first green run.

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
