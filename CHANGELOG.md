# Changelog

All notable changes to Asset Pilot are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases use
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-07-16

### Added

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
  reconciliation. An `operation_run_backlog` health check warns when a run stays queued past
  `operation_runs.stale_queued_warning_seconds` (default 86400) without ever auto-failing a legitimate
  backlog.
- Exact, configurable scan and export budgets for authorized listings and duplicate detection
  (`listing.scan_budget` / `batch_size` / `export_max_rows`, `duplicates.group_scan_budget` /
  `export_group_scan_budget`); every bounded listing returns an explicit `truncated` flag and every
  ceiling-bounded CSV export appends a truncation marker row, so a scan ceiling can never masquerade as
  end-of-data.

### Changed

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
- Persisted journal, delivery, operation-run, plan-claim, dependency, and asset-deletion-fence state
  across fourteen bundle-owned tables.
- Treat skipped operation-run items as deliberate terminal outcomes; retry is limited to blocked,
  failed, and cancelled items.
- Ship the compiled Studio remote in the Composer archive so production installs do not require a
  JavaScript toolchain.
- Support asset-referencing element saves inside a consumer-owned database transaction via a dedicated
  autocommit sidecar connection that carries the dirty marker, dependency edge, and deletion-fence
  read; the prior rejection of saves under an ambient transaction is removed. See UPGRADING.md for the
  two documented limitations (same-transaction asset+reference creation, and phantom-edge over-block
  after a rolled-back save).

### Fixed

- Enforce the configured asset protection property under lock for duplicate hard-delete, filename
  normalization, audit revert, and quarantine restore; protection state is part of reviewed
  fingerprints.
- Revalidate rule-specific and filename-normalization apply state under the mutation lock.
- Aggregate duplicate legacy storage snapshots before assigning run IDs and backfill first-assignment
  markers before retired audit statuses are consolidated.
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

### Security

- Removed vulnerable locked dependency versions and added release-blocking Composer and npm audits.
- Pinned release workflow actions to immutable revisions.

### Upgrade notes

Read [UPGRADING.md](UPGRADING.md) before deploying this release.

[Unreleased]: https://github.com/oronts/pimcore-asset-pilot-bundle/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/oronts/pimcore-asset-pilot-bundle/compare/v1.1.0...v2.0.0
