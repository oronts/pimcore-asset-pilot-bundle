# Upgrading Asset Pilot

## Upgrade to 2.0.0

Read the operational sequence in [the installation guide](docs/installation.md#upgrade-to-20) before
changing production code. Pause producers, drain the `asset_pilot` and `pimcore_maintenance`
consumers, back up the database and configuration, deploy the package, run Pimcore migrations,
clear the cache, restart both consumers, and then run the health command.

### Dependency baseline

Version 2.0 requires PHP 8.4 or newer, Pimcore 12.3.11 or newer within the 12.x line, Symfony 7.3 or
newer within the 7.x line, Pimcore Studio Backend 2025.4.7 or newer, and Pimcore Studio UI 2025.4.8
or newer. Resolve the updated Composer lock in the consuming application before deployment.

### Database migration

The 2.0 migration reconciles all Asset Pilot tables, columns, primary keys, and indexes. It is safe
to run repeatedly and repairs partially applied older schemas. Do not delete audit or operation
tables manually before the migration.

The migration also creates the durable first-assignment marker for assets with historical
`completed` or `completed_with_observer_error` audit entries. Both statuses mean the asset move was
committed. An asset cannot be reconstructed when audit logging was disabled or its history was
already pruned. Review those assets before enabling first-assignment-only rules.

The journal migration adds intent, actor, parent, recovery, and commit metadata plus the
`asset_pilot_operation_delivery` outbox table. Its nullable `audit_reconciled_at` marker keeps dead
warning updates and delivered warning cleanup repairable after a database failure or process crash;
maintenance retries this audit-only work without delivering the observer again. Signed-plan claims
now use the `asset_pilot_apply_plan_claim` table so one-time consumption is atomic across every web and
worker process and does not depend on an evictable cache entry. Historical `action_failed` rows become
`completed_with_observer_error`; the retired status is no longer accepted by source, API, CLI, or
Studio UI. External audit-log consumers must accept the new recovery and observer-error statuses
and must stop depending on `action_failed`.

The dependency migration adds `asset_pilot_dependency_source`, `asset_pilot_dependency_edge`, and
`asset_pilot_dependency_freshness`. After migrations, run
`asset-pilot:rebuild-dependency-projection` repeatedly until it reports `ready`, then verify
`asset-pilot:health`. Destructive unused-asset operations return `unknown` and fail closed while the
projection is building, failed, or contains dirty sources.

Existing storage snapshot rows are grouped by capture timestamp into the new durable run model.
Their counts, byte totals, unknown-size totals, type rows, and timestamps are preserved.

Bulk organization and duplicate merge now use `asset_pilot_operation_run` and
`asset_pilot_operation_run_item`. Duplicate items persist their reference-repoint and disposition
phase so an interrupted merge can resume with `asset-pilot:merge-duplicates --run-id=... --apply`
or the actor-scoped REST run ID.

### Timestamp normalization

Version 2.0 normalizes every timestamp writer to an explicit UTC clock and serializes every REST
timestamp as RFC 3339 in UTC through a single formatting seam. No data migration rewrites existing
rows. The stored column format is unchanged, so no schema step is required.

There is one behavioral note for installs whose server timezone was not UTC before the upgrade.
Rows written before 2.0 were stamped in ambient server local time and stored without an offset.
On read they are now interpreted as UTC, so a historical row can display shifted by the old local
offset (for example two hours for a `Europe/Berlin` summer host). New rows are correct. The shift
is display-only, bounded by the previous server offset, and affects only pre-upgrade history. If
your reporting depends on exact historical wall-clock values, snapshot the affected tables before
upgrading. Running the application under UTC (the recommended configuration) avoids the shift
entirely.

### Studio assets

The Composer package now contains a prebuilt Studio remote. Deploy `public/studio/build` with the
rest of the package and expose it through Pimcore's bundle public assets. Do not run npm in the
application deployment. Custom source builds publish an immutable generation and atomically switch
`active.json`; never copy only part of a generation.

### Workers and locks

The `asset_pilot` transport and Pimcore's `pimcore_maintenance` transport need active Messenger
consumers. Configure a failure transport and monitor it. Multi-node deployments must use shared
cache and lock backends. Set `oronts_asset_pilot.idempotency.lock_ttl` above the longest expected
asset operation and restart workers after changing it.

Route `Oronts\AssetPilotBundle\Message\OperationDeliveryMessage` and
`Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage` to `asset_pilot` together with
the organize messages. Synchronous organization still needs this consumer for durable actions and
events; Pimcore maintenance polls the database outbox so broker failures are recoverable.

`APP_SECRET` must be non-empty because it signs every reviewed apply plan. Selection-based REST
and CLI mutations reject apply requests without a fresh matching plan token. Configure a shared lock
backend for multi-node deployments; plan consumption itself is database-backed.

Review the new `oronts_asset_pilot.operation_journal` settings before production rollout:

```yaml
oronts_asset_pilot:
    operation_journal:
        recovery_after_seconds: 900
        delivery_batch_size: 100
        dispatch_deduplication_seconds: 3600
        max_attempts: 5
        base_retry_seconds: 30
        max_retry_seconds: 3600
        lease_seconds: 300
```

Review the new `oronts_asset_pilot.operation_runs` settings as well:

```yaml
oronts_asset_pilot:
    operation_runs:
        lease_seconds: 300
        stale_queued_warning_seconds: 86400
```

Keep `operation_runs.lease_seconds` above the longest single asset operation (same rule as
`idempotency.lock_ttl` and `operation_journal.lease_seconds`); maintenance now fails an in-flight run
item only when its claim-token-fenced lease expires. A run queued longer than
`stale_queued_warning_seconds` surfaces as an `operation_run_backlog` health warning and is never
auto-failed; an operator cancels and retries it.

After migration, preview any stale unfinished entries and apply only the signed reviewed result:

```bash
bin/console asset-pilot:recover-operations --limit=100
bin/console asset-pilot:recover-operations --limit=100 --apply --plan-token='v1...'
```

Recovery restores the recorded actor, rechecks publish permission, and holds the shared asset lock.
It classifies the persisted result and never repeats a move or revert.

If readiness reports dead observer deliveries, review and requeue only the exact signed scope:

```bash
bin/console asset-pilot:retry-deliveries --limit=100
bin/console asset-pilot:retry-deliveries --limit=100 --apply --plan-token='v1...'
```

Requeueing resets the delivery lease and bounded-attempt state. The delivery still restores the
initiating actor and repeats its declared asset authorization before execution.

### REST prefix

Asset Pilot routes now derive from `pimcore_studio_backend.url_prefix`. Integrations that hardcoded
`/pimcore-studio/api/asset-pilot` must use the configured Studio prefix instead.

### Authorization behavior

Programmatic `organize()` calls now enforce the resolved actor's object `publish` permission and
return no operations when denied. HTTP requests, automatic listeners, and queued retries carry and
re-resolve the initiating Pimcore user; supervised CLI and maintenance use the explicit system actor.

### Consumer database transactions

2.0 supports saving an element that references a tracked asset inside your own open database
transaction. The prior hard rejection of such saves is removed: the dirty marker, dependency edge, and
deletion-fence read are carried on a dedicated autocommit sidecar connection
(`ProjectionMarkerConnectionInterface`, an extensible non-final seam) so they stay visible under your
ambient transaction. Two limitations follow: (a) creating an asset *and* an element referencing it in
the same ambient transaction is unsupported; (b) rolling back an otherwise successful save leaves the
already-committed dependency edge as a phantom that conservatively blocks a later delete of that asset
(a safe over-block) until the projection is rebuilt or the source is reindexed. The asset delete path
itself still cannot run inside an ambient transaction.

### Scan and export budgets

Authorized listings and duplicate detection now scan a bounded number of rows and groups instead of
walking an unbounded result set. Review the defaults before rollout and raise them only where a
larger authoritative page or export is genuinely required:

```yaml
oronts_asset_pilot:
    listing:
        scan_budget: 5000
        batch_size: 100
        export_max_rows: 200000
    duplicates:
        group_scan_budget: 5000
        export_group_scan_budget: 500000
```

`listing.scan_budget` and `duplicates.group_scan_budget` cap how many raw rows or duplicate groups a
single authorized page scans while filling past natively hidden entries. A page that cannot be
resolved within its budget returns an explicit `truncated` flag. `listing.export_max_rows` and
`duplicates.export_group_scan_budget` cap CSV exports, which stop at the ceiling and append a
truncation marker row. A scan ceiling therefore never masquerades as end of data. REST and CLI
consumers that page or export must read the `truncated` flag and the export marker row instead of
treating a short result as complete.

### Removed configuration

Remove `oronts_asset_pilot.content_scan.classes` from application configuration. The content guard
now discovers all supported Pimcore content tables and rejects this obsolete allowlist key.

Replace `oronts_asset_pilot.content_scan.max_sources` with
`oronts_asset_pilot.dependency_projection.bootstrap_max_sources`. The budget now applies only to the
one-time live bootstrap fallback; normal safety checks use the indexed projection.

Remove `oronts_asset_pilot.audit.enabled`. The journal is mandatory and the removed switch is
rejected instead of ignored.

The `--dry-run` aliases were removed from `asset-pilot:heal-assets` and
`asset-pilot:cleanup-unused`. Both commands preview by default; omit `--apply` for a read-only run.

`DuplicateMergeServiceInterface` now separates `preview()` from `merge()`. `merge()` requires the
exact reviewed fingerprint map and has no nullable or dry-run shortcut. Update direct service
consumers and custom replacements accordingly. `AssetZipServiceInterface` now returns the immutable
`ZipBuildResult` value object instead of an array.

`ConflictStrategyInterface::resolve()` now receives `bool $dryRun`. Update direct custom conflict
strategies to accept the fourth argument. Callback services now implement the focused
`CallbackDecisionInterface::decide()` method; plain callable callbacks receive the same four
arguments. The decision method is a query-only
contract because it runs while signed previews are built as well as immediately before apply.

`RuleActionInterface` changed incompatibly in 2.0. Custom actions must implement a side-effect-free
`prepare()` and `applyPrepared(Asset $asset, array $payload,
RuleActionDeliveryContextInterface $delivery)`. Use `deliveryId()` as the stable idempotency key and
call `heartbeat()` during long work. There is no 1.x compatibility adapter. See `docs/extending.md`.

`DuplicateMergeStrategyInterface::disposeCopy()` changed incompatibly in 2.0. Its signature is now
`disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition`: the
leading `int $copyId` parameter is gone and the fenced context is mandatory. The opt-in
`ContextAwareDuplicateMergeStrategyInterface` and its `disposeCopyWithContext()` method are removed.
Migrate a custom strategy by dropping the `int $copyId` argument, reading `copyId()` / `canonicalId()` /
`actor()` from the context, calling `heartbeat()` during long disposals so the run item and every held
asset/referrer lock stay owned, and using `save(callable)` for guarded copy writes under the held lock.
See `docs/extending.md`.

Custom `DurableOperationObserverInterface` services must implement
`requiredAssetPermission(): ?string`. Return the Pimcore asset permission needed by `deliver()` so
the processor reauthorizes and locks the asset. Return `null` only for metadata-only delivery that
does not load or mutate the asset.

`AuditLoggerInterface` was removed in 2.0. Inject the focused capability required by each consumer:
`AuditWriterInterface` for operation writes, `AuditQueryInterface` for history and aggregates,
`AuditExportInterface` for streaming exports, or `AuditRetentionInterface` for retention cleanup.
`AuditLogger` implements all four defaults, and each interface has its own replaceable Symfony alias.
Decorators should implement only the capability they change; there is no monolithic compatibility adapter.

Custom `PathResolverInterface` implementations must add `validateTemplate(string): void`, and custom
`ConditionEvaluatorInterface` implementations must add `validateSyntax(string): void`. The config validator calls
these methods against the same consumer implementation used at runtime.

`NotifierInterface::notify()` now receives one immutable `Notification` instead of separate title and message
strings. Read its stable `kind`, typed `severity`, presentation fields, and safe scalar `context`; notification
fan-out itself is replaceable through `NotificationDispatcherInterface`.

Direct `OperationRunStoreInterface` and `OrganizeDispatcherInterface` replacements now receive
`OperationRunKind` rather than arbitrary kind strings. Serialize the enum's `value` only at database, REST, or
message boundaries.

### Rollback

Code rollback does not remove the repaired schema or historical first-assignment markers. The
migration intentionally has no destructive down operation.
