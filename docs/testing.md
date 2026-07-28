[← Documentation index](index.md) · [Project README](../README.md)

# Testing

The bundle ships with a broad kernel-free unit suite. It is one layer of evidence, not a substitute
for a live Pimcore database, firewall, worker, storage, and browser compatibility suite.

```bash
composer install
vendor/bin/phpunit
```

Tests live under `tests/Unit/`, mirroring the `src/` namespace layout. High-risk services,
controllers, configuration, migrations, health checks, messages, and frontend publication have
targeted regression tests; integration boundaries remain application-level acceptance tests.

```
tests/
├── Fixtures/               OpenAPI spec info for API tests
├── Support/                Cross-suite test doubles
├── Unit/
│   ├── Action/             Rule action preparation, validation, and durable delivery
│   ├── Api/                API serialization and RFC 3339 date formatting
│   ├── Audit/              Audit logging, journal lifecycle, retention, and exports
│   ├── Cache/              Stats caching and invalidation
│   ├── Command/            Preview/apply guards, recovery, duplicate merge, and CLI support
│   ├── Condition/          Expression evaluation and extension functions
│   ├── Contract/           Frontend translation contract
│   ├── Controller/         REST parsing, authorization, plans, recovery, and operation runs
│   ├── DependencyInjection/ Configuration tree, aliases, tags, and Messenger routing
│   ├── Engine/             Rule matching and explain traces
│   ├── Enum/               Public enum values and status semantics
│   ├── Event/              Typed move, durable, mutation, heal, and duplicate-merge events
│   ├── EventListener/      Save/upload dispatch, actor context, and loop guards
│   ├── Filter/             Type, size, extension, and composite filters
│   ├── Health/             Schema, queue, worker, cache, dependency, and journal checks
│   ├── Integrity/          Detection and reversible healing
│   ├── Maintenance/        Outbox polling, retention, purge, and snapshots
│   ├── Merge/              Durable duplicate phases, repointing, and dispositions
│   ├── Message/            Organization, run, and exact delivery messages
│   ├── MessageHandler/     Actor restoration, staleness, and delivery handling
│   ├── Migrations/         Released-schema reconciliation and data backfills
│   ├── Model/              Immutable operation, plan, delivery, and rule payloads
│   ├── Naming/             Safe filename and collision resolution
│   ├── Notification/       Bulk-run notification dispatch
│   ├── Observer/           Durable event/action preparation and delivery
│   ├── PathResolver/       Template rendering, context, and validation
│   ├── Security/           Actor, element authorization, and reviewed-plan contracts
│   ├── Service/            Organizer, journal, delivery, recovery, operation runs, and queries
│   ├── Strategy/           Move-conflict strategies
│   ├── Support/            Shared parsing and bounded-ID helpers
│   ├── Tools/              Release-archive verifier
│   ├── Webpack/            Active-generation Studio publication
│   ├── Zip/                Zip build result
│   ├── DependencyProjectionSchemaTest.php
│   ├── InstallerTest.php
│   └── OrontsAssetPilotBundleTest.php
└── bootstrap.php
```

Static-coupled code (controllers and services that call Pimcore static APIs) is unit-tested through
protected method seams overridden in anonymous subclasses, and through pure helper classes
(`SortWhitelist`, `ConfidenceFilter`, `Like`, `AssetSortColumns`), so the whole suite stays
kernel-free.

Run every local release gate:

```bash
composer validate-project
composer audit --locked --no-dev
composer ci
npm ci --prefix assets/studio
npm audit --prefix assets/studio --audit-level=low
npm --prefix assets/studio run check-types
npm --prefix assets/studio run lint
npm --prefix assets/studio test
npm --prefix assets/studio run test:a11y
npm --prefix assets/studio run build
npm --prefix assets/studio run prepare-release-build
npm --prefix assets/studio run verify-build
composer archive --format=zip --file=asset-pilot-bundle
php tools/verify-release-archive.php asset-pilot-bundle.zip
```

Before production rollout, add host-application tests for real MySQL schema upgrades, custom Studio
prefix routing, restricted asset/object/document workspaces, queue retry/failure behavior, shared
locks across processes, remote storage failures, and the supported Studio browser matrix. These
deployment-level checks are defined as an acceptance matrix in
[End-to-end acceptance](e2e-acceptance.md) (Playwright + axe under `assets/studio/e2e/`); the matrix
is a scaffold with a couple of smoke checks, not yet a wired gate.
