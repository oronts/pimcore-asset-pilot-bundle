# Changelog

All notable changes to Asset Pilot are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases use
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - unreleased

Asset Pilot 2.0 makes every destructive operation durable, reviewable, and recoverable. Automatic
organization moves to a transactional producer outbox, every mutating workflow runs through a signed
single-use preview and apply, and the compiled Studio remote now ships inside the Composer archive so a
production install needs no JavaScript toolchain. This is a major release with breaking changes and a new
operational requirement (a scheduled `pimcore:maintenance`). Read [UPGRADING.md](UPGRADING.md) before you
deploy.

### Added

- Asset format conversion. A new `convert_format` rule action re-encodes an image asset to a target
  format (`png`, `jpeg`, `gif`, or `webp`) after it is organized, through a pluggable converter seam:
  implement `AssetConverterInterface` and tag it `oronts_asset_pilot.asset_converter` to add Imagick,
  vips, or external-binary encoders. A GD-backed converter ships by default. The action is best-effort by
  contract: a non-image asset, an asset already in the target format, a missing encoder, or a source the
  converter cannot decode all skip without failing the organize. The resolver is the overridable
  `AssetConverterResolverInterface`.
- CSV asset distribution. A new `asset-pilot:distribute-from-csv <file>` command moves existing assets
  into target folders from a CSV mapping, resolving an asset by id or path and a target folder by id or
  path (configurable `--asset-column` and `--target-column`), for one-off imports and migrations that
  place assets by an external plan rather than by rules. It previews by default and moves only with
  `--apply`; every row is reported (planned, moved, already in place, unknown asset, missing target, or
  invalid) so one bad row never aborts the run, each move honors the asset lock and excluded folders and
  is audited, and the behavior is overridable through `CsvDistributionServiceInterface`.
- Persisted dry-run simulations. A new `POST /operations/simulate` records the moves that organizing a
  data object would make as a durable, terminal operation run (a new `simulation` run kind) without
  changing anything, so the diff can be reviewed later. The Studio Operations tab gains a "Simulate and
  save" action and a recorded-simulations panel that shows each simulated asset's source and target path.
  `GET /operations/runs` gains an optional `kind` filter. Simulation runs are read-only (View permission),
  terminal, and never dispatched, so they cost no worker and are pruned by the usual run retention. The
  recorded run items are built through the overridable `OperationsController::simulationItems()` seam.
- Studio asset-tree organize action. Right-clicking an asset folder in the Studio tree offers "Organize
  with Asset Pilot", which opens the dashboard on the Operations tab with the clicked folder pre-filled
  into the reviewed Reorganize form. It never mutates in one click: the organize still runs through the
  existing preview and apply. The item is gated to folder nodes and to actors who can operate.
- Mandatory pre-mutation operation journal with persisted intent, actor, recovery metadata, and
  outcome-specific prepared deliveries.
- Database-backed durable observer outbox with exact delivery messages, leases, bounded retry,
  dead-delivery health, actor restoration, and optional observer-declared asset ACL/locking.
- Signed preview/apply recovery command that classifies stale move and revert state without
  repeating the mutation.
- Actor-scoped durable operation runs and items, including resumable duplicate merge phases and
  retry support for blocked, failed, and cancelled items. Deliberately skipped items remain terminal.
- Signed REST preview/apply plans for duplicate merge and actor-scoped run resumption.
- Signed, single-use CLI preview/apply plans for every destructive selection workflow, including
  organization, unused cleanup, duplicate merge, healing, filename normalization, empty-folder
  sweep, quarantine purge, replay, reorganization, journal recovery, and delivery retry.
- Bounded maintenance retention for terminal operation runs. Active and recent runs are preserved,
  and retry chains are pruned safely from leaf to parent.
- Typed duplicate-merge committed event and durable operation success/failure events.
- Atomic Studio build publication with an active-generation pointer and build verification.
- Idempotent schema reconciliation for upgrades from released and partially installed schemas.
- Durable first-assignment markers backfilled from completed audit history where evidence exists.
- Configurable idempotency lock lifetime for long-running organization operations.
- Durable indexed dependency projection with revision-fenced Pimcore lifecycle updates, explicit
  safe/referenced/unknown verdicts, resumable bounded bootstrap, and live freshness health details.
- Release provenance, dependency inventories, dependency audits, frontend type checks, and
  packaged-build verification.
- Claim-token-fenced liveness lease on operation-run items (`operation_runs.lease_seconds`, default
  300); maintenance fails only items whose durable lease expired, replacing the age-based stale
  reconciliation. An `operation_run_backlog` health check warns when a run stays awaiting dispatch or
  queued past `operation_runs.stale_queued_warning_seconds` (default 86400) without ever auto-failing a
  legitimate backlog.
- Exact, configurable scan and export budgets for authorized listings and duplicate detection
  (`listing.scan_budget` / `batch_size` / `export_max_rows`, `duplicates.group_scan_budget` /
  `export_group_scan_budget`); every bounded listing returns an explicit `truncated` flag and every
  ceiling-bounded CSV export appends a truncation marker row, so a scan ceiling can never masquerade as
  end-of-data.
- New `pending_dispatch` operation-run status for the automatic-organization producer outbox, surfaced
  in the REST run status, OpenAPI, Studio types and styling, EN/DE translations, and health. It is
  cancellable, and cancelling it terminates the run before publication.
- Classification-store asset references are now included in dependency safety. `AssetFieldExtractor`
  traverses an object's classification-store fields, and the dependency projection, live scanner, and
  pre-save deletion fence all consume the result. An incompletely readable classification store is
  treated as fail-closed: the source is kept dirty and the usage verdict is `unknown`, so a
  still-referenced asset can never pass an unused-asset, quarantine-purge, or duplicate-hard-delete gate.
- The PHP release-archive verifier now recomputes the Studio source hash from the archived sources and
  rejects a build whose source no longer matches its recorded `sourceHash`, matching the JavaScript
  build check (`npm run verify-build`) byte for byte.

### Changed

- Automatic organization (data-object save and asset-upload listeners) now uses a transactional producer
  outbox. The listener records a committed `pending_dispatch` operation run inside the (possibly
  consumer-owned) save transaction instead of publishing a Messenger message directly, and a Pimcore
  maintenance task publishes only committed pending runs after the transaction commits. This removes the
  pre-commit publish race, so a rolled-back save leaves no phantom message and a worker never sees a run
  before it is committed. Automatic organization now requires `pimcore:maintenance` to be scheduled; see
  UPGRADING.md. Manual and controller-triggered organization publish directly and are unaffected.
- Automatic organize producers bind a durable per-object coalescing intent (the
  `asset_pilot_automatic_organize_intent` table) so concurrent saves of the same object, including two
  inside one source transaction, fold into a single pending run instead of creating duplicate runs. The
  intent and its pending run are written in one transaction; a save that folds in through the fast cache
  path is recorded durably too; the drain and the maintenance backstop rotate a coalesced save by
  atomically re-recording a fresh pending run rather than an ephemeral dispatch, so a crash, an
  undispatchable or exhausted run, or a purged run never loses it; and an outbox write that fails inside a
  caller-owned transaction now rolls back with the source save instead of being swallowed.
- The `operation_run_backlog` health check and its backlog count now include `pending_dispatch` runs, so a
  stranded producer backlog (for example when `pimcore:maintenance` is not scheduled) is visible instead of
  silent.
- Conflict strategies now receive an explicit `dryRun` flag; custom decisions and preview listeners
  have a documented query-only contract.
- Callback services now implement the focused auto-tagged `CallbackDecisionInterface`; configuration
  validation resolves and type-checks the selected callback before reporting success.
- Split audit writing, querying, export, and retention into focused aliases; health aggregation,
  notification fan-out, integrity selection, and operation-run kinds now use typed public contracts.
- Released as 2.0 because durable rule actions now use the prepare/deliver contract and receive a
  `RuleActionDeliveryContextInterface` with stable idempotency and lease heartbeat capabilities.
- `DuplicateMergeStrategyInterface::disposeCopy()` now receives `(RepointReport, DuplicateMergeContextInterface)`
  with the fenced context mandatory (stable idempotency key, lease `heartbeat()`, guarded `save()`); the
  opt-in `ContextAwareDuplicateMergeStrategyInterface` and `disposeCopyWithContext()` are removed.
- Added replaceable organizer, planner, dispatcher, ZIP, plan-claim, integrity-eligibility,
  dependency projection, freshness, rebuild, and usage-verifier service interfaces; custom
  condition and path resolvers now own their validation behavior too.
- Moved signed apply-plan consumption from `cache.app` to an atomic database claim store.
- Raised maintained dependency baselines to Pimcore 12.3.11, Symfony 7.3, and the 2025.4 Studio
  packages.
- Declared every directly used PHP package and runtime extension explicitly.
- Made the REST route follow Pimcore Studio's configured backend prefix.
- Expanded audit path columns to the supported 765-character Pimcore path limit.
- Expanded health checks to report schema drift, uncertain shared-cache adapters, and unverified
  async consumer liveness without false-positive healthy states. Required worker heartbeats now
  make missing or stale consumers visible as critical. Dependency health now reports the live
  projection generation, cursor, counts, dirty sources, and readiness state.
- Added operation-journal health for stale or recovery-required operations and overdue or dead
  observer deliveries. Durable-delivery routing and both consumer heartbeats are required even when
  organization itself is synchronous.
- Persisted journal, delivery, operation-run, plan-claim, dependency, asset-deletion-fence, and
  automatic-organize-intent state across fifteen bundle-owned tables.
- Treat skipped operation-run items as deliberate terminal outcomes; retry is limited to blocked,
  failed, and cancelled items.
- Ship the compiled Studio remote in the Composer archive so production installs do not require a
  JavaScript toolchain.
- Support asset-referencing element saves inside a consumer-owned database transaction via a dedicated
  autocommit sidecar connection that carries the dirty marker, dependency edge, and deletion-fence
  read; the prior rejection of saves under an ambient transaction is removed. See UPGRADING.md for the
  two documented limitations (same-transaction asset and reference creation, and phantom-edge over-block
  after a rolled-back save).

### Fixed

- Enforce the configured asset protection property under lock for duplicate hard-delete, filename
  normalization, audit revert, and quarantine restore; protection state is part of reviewed
  fingerprints.
- Revalidate rule-specific and filename-normalization apply state under the mutation lock.
- Collapse duplicate legacy storage snapshots by deterministically retaining the earliest row per
  (captured_at, type) and discarding the same-second duplicates before assigning run IDs, then
  backfill first-assignment markers before retired audit statuses are consolidated.
- Dead-letter expired deliveries at the attempt cap, fence lease heartbeats by claim token, prevent
  concurrent journal completion overwrites, and deduplicate repeated queue dispatches.
- Treat a MariaDB zero changed-row lease heartbeat as valid only after a claim-token-fenced ownership
  query confirms the processing lease is still active.
- Prune inactive Studio generations before Composer packaging so the verified archive contains only
  the build referenced by `active.json`.
- Persist terminal delivery audit reconciliation so dead warnings and delivered warning cleanup
  recover after database failure or process interruption without repeating observer work.
- Compensate newly created operation runs when broker dispatch fails and prevent duplicate retry
  child runs at both transaction and database levels.
- Scope durable delivery keys by observer, make prepared ZIP tokens single-use, and render skipped run
  items with localized labels.
- Reuse hard-coded content-reference scan results for the same asset path within one request or
  message, while resetting them between executions. Large quarantine and cleanup applies no longer
  repeat the full Pimcore content scan for fingerprinting and mutation safety.
- Use theme-safe semantic text colors for success, warning, and error states so status content keeps
  WCAG AA contrast in light, dark, and tinted Studio surfaces.
- Studio Unused Assets tab now lets you select locked rows and reach the bulk-unlock action; delete,
  move, and quarantine bulk operations act on the unlocked subset and flag locked assets as skipped.
- The ZIP service authorizes the source object before extracting its asset relationships, so an actor who
  cannot view an object no longer learns its asset associations; folder ZIP creation pages past
  unauthorized rows instead of dropping authorized assets that follow them in a large shared folder.
- The dispatch relay fails an undispatchable pending run (unrecognized trigger or actor, or no targets)
  instead of leaving it in `pending_dispatch` indefinitely and invisible to the backlog health check.

### Security

- Removed vulnerable locked dependency versions and added release-blocking Composer and npm audits.
- Updated `guzzlehttp/guzzle` to 7.15.2 to resolve CVE-2026-69245 and CVE-2026-69246 (noncanonical host
  and cookie-domain handling in a transitive dependency).
- Refreshed the Studio npm lockfile to clear the `brace-expansion`, `fast-uri`, and `dompurify`
  advisories. The remaining transitive `react-router` advisories are provided by the host through
  `@pimcore/studio-ui-bundle` (not bundled in this remote; see SECURITY.md) and are deferred to the
  coordinated Pimcore Studio 2026.1 upgrade.
- Pinned release workflow actions to immutable revisions.

### Upgrade notes

Read [UPGRADING.md](UPGRADING.md) before deploying this release.

[2.0.0]: https://github.com/oronts/pimcore-asset-pilot-bundle/compare/v1.1.0...v2.0.0
