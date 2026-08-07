[← Documentation index](index.md) · [Project README](../README.md)

# Configuration

Create `config/packages/oronts_asset_pilot.yaml`:

```yaml
oronts_asset_pilot:
    enabled: true

    # Optional global allowlist. Empty means the save listener reacts to every class.
    allowed_classes: []

    # Optional: restrict localized-field scanning to these locales. Empty means all valid languages.
    locales: []

    rules:
        product_images:
            class: Product
            fields: [images, galleryImages]
            condition: 'object.getItemNumber() != null'
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: always
            priority: 100
            filters:
                types: [image]
                max_size: 52428800

        product_documents:
            class: Product
            fields: [datasheet, manual, brochure]
            target_path: '/Products/{{ object.getItemNumber() }}/Documents{{ locale ? "/" ~ locale : "" }}'
            strategy: always
            priority: 70
            # Optional: restrict this rule to specific locales of a localized field. Empty (default)
            # means all locales, and the rule then also applies to non-localized fields.
            locales: [en, de]

    naming:
        collision_pattern: counter
        slugify: true

    async:
        enabled: true
        batch_size: 50
        transport: asset_pilot
        failure_transport: asset_pilot_failed
        max_queue_depth: 1000
        worker_heartbeat_max_age: 120

    idempotency:
        lock_ttl: 60
        max_object_replays: 3

    operation_journal:
        recovery_after_seconds: 900
        delivery_batch_size: 100
        dispatch_deduplication_seconds: 3600
        max_attempts: 5
        base_retry_seconds: 30
        max_retry_seconds: 3600
        lease_seconds: 300

    operation_runs:
        retention_days: 90
        retention_batch_size: 500
        lease_seconds: 300
        stale_queued_warning_seconds: 86400
        abandoned_run_failover_seconds: 86400

    audit:
        retention_days: 90

    protection:
        exclude_folders:
            - /Protected/
            - /Manual/
        lock_property: asset_pilot_locked

    confidence:
        recently_uploaded_days: 30
        probably_unused_days: 90

    quarantine:
        folder: /Quarantine
        grace_days: 30

    integrity:
        enabled: true
        skip_extensions: [svg]
        on_unrecoverable: report   # report | quarantine

    content_scan:
        enabled: false

    dependency_projection:
        bootstrap_live_scan: true
        bootstrap_max_sources: 50000
        rebuild_batch_size: 1000
        deletion_fence_lease_seconds: 900
        deletion_fence_reap_batch_size: 1000
        reconcile_stale_seconds: 300

    storage_snapshots:
        enabled: true
        minimum_interval_seconds: 3600
        retention_days: 365

    duplicates:
        merge_strategy: quarantine
        group_scan_budget: 5000            # groups scanned per list page before it reports truncated
        export_group_scan_budget: 500000   # groups scanned per CSV export before a truncation marker row

    listing:
        scan_budget: 5000        # raw rows an authorized list page scans past denials before truncated
        batch_size: 100          # raw rows fetched per window while scanning a page
        export_max_rows: 200000  # rows an authorized CSV export streams before a truncation marker row

    cache:
        stats_ttl: 60
        unused_stats_ttl: 300

    zip:
        default_strategy: flat
        max_assets: 1000
        max_uncompressed_bytes: 536870912
        download_token_ttl: 300

    notifications:
        enabled: false
        failure_rate_threshold: 0.5   # 0..1
        recipient_user_ids: []        # Pimcore backend users to notify in-app (all are notified)
        recipient_group_ids: []       # and/or user groups (roles)
        sender_user_id: 0
```

## Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Enable automatic organization from Pimcore object-save and asset-upload events; explicit API, CLI, queue, and maintenance operations remain available |
| `allowed_classes` | `string[]` | `[]` | Global allowlist of DataObject classes the save listener reacts to. Empty means all classes |
| `locales` | `string[]` | `[]` | Restrict localized-field scanning to these locales. Empty means all valid Pimcore languages |
| `rules` | `map` | `[]` | Named rule definitions (see below) |
| `naming.collision_pattern` | `enum` | `counter` | Filename collision resolution: `counter`, `timestamp`, `uuid` |
| `naming.slugify` | `bool` | `true` | Slugify filenames during organization |
| `async.enabled` | `bool` | `true` | Dispatch moves via Symfony Messenger |
| `async.batch_size` | `int` | `50` | Default operations per batch message, used as the default for the `--batch-size` CLI option and the bulk API `batchSize` |
| `async.transport` | `string` | `asset_pilot` | Messenger receiver for organization messages and durable journal deliveries |
| `async.failure_transport` | `string` | `asset_pilot_failed` | Messenger receiver inspected for failed Asset Pilot messages |
| `async.max_queue_depth` | `int` | `1000` | Health warning threshold for the configured Asset Pilot queue |
| `async.worker_heartbeat_max_age` | `int` | `120` | Maximum age in seconds for both required worker heartbeats; minimum 5 |
| `idempotency.lock_ttl` | `float` | `60` | Renewable mutation and target-allocation lock lifetime in seconds; must be greater than zero |
| `idempotency.max_object_replays` | `int` | `3` | Maximum latest-state passes before a continuously changing object fails and must be retried |
| `operation_journal.recovery_after_seconds` | `int` | `900` | Age after which unfinished move/revert journal entries are eligible for safe state reconciliation |
| `operation_journal.delivery_batch_size` | `int` | `100` | Maximum due durable observer deliveries queued by one maintenance pass; 1..1000 |
| `operation_journal.dispatch_deduplication_seconds` | `int` | `3600` | Native Messenger queue deduplication TTL for one delivery ID |
| `operation_journal.max_attempts` | `int` | `5` | Delivery attempts before a durable observer delivery becomes dead and health turns critical |
| `operation_journal.base_retry_seconds` | `int` | `30` | Initial durable delivery retry delay |
| `operation_journal.max_retry_seconds` | `int` | `3600` | Retry delay ceiling; must be at least `base_retry_seconds` |
| `operation_journal.lease_seconds` | `int` | `300` | Claim lease for one observer delivery; expired claims are safely reclaimable |
| `operation_runs.retention_days` | `int` | `90` | Retain terminal actor-scoped runs for this many days; active runs are never pruned and retry chains are removed leaf-first |
| `operation_runs.retention_batch_size` | `int` | `500` | Maximum expired terminal runs pruned by one Pimcore maintenance pass; 1..1000 |
| `operation_runs.lease_seconds` | `int` | `300` | Durable liveness lease (seconds) for an in-flight operation-run item; a worker renews it each heartbeat and maintenance fails an item whose lease expired. Keep it above the Symfony lock TTL and the longest single-asset save; min 1 |
| `operation_runs.stale_queued_warning_seconds` | `int` | `86400` | A run left awaiting dispatch (unscheduled maintenance relay) or queued (lost broker message) longer than this is surfaced as a health warning; it is never auto-failed, an operator cancels/retries it. min 60 |
| `operation_runs.abandoned_run_failover_seconds` | `int` | `86400` | Last-resort failover for a Running run whose in-flight item lease has expired (crashed worker, or a synchronous run whose process died) and that has stalled longer than this; a run still heartbeating a live lease, and a queued backlog, are never failed. Separate from the warning threshold. min 60 |
| `audit.retention_days` | `int` | `90` | Days to retain audit entries |
| `protection.exclude_folders` | `string[]` | `[]` | Folders excluded from organization (e.g., `["/Protected/"]`) |
| `protection.lock_property` | `string` | `asset_pilot_locked` | Custom property name used to lock assets |
| `confidence.recently_uploaded_days` | `int` | `30` | Assets modified within this many days score `recently_uploaded` |
| `confidence.probably_unused_days` | `int` | `90` | Below this (and past `recently_uploaded_days`) scores `probably_unused`; older scores `definitely_unused` |
| `quarantine.folder` | `string` | `/Quarantine` | Folder quarantined assets are moved to instead of being deleted (reversible) |
| `quarantine.grace_days` | `int` | `30` | Days a quarantined asset is kept before the purge task may hard-delete it (if still unused) |
| `integrity.enabled` | `bool` | `true` | Enable broken-asset detection (the `findBroken` scan; explicit `--by-ids`/`?ids` checks ignore this) |
| `integrity.skip_extensions` | `string[]` | `[svg]` | Extensions skipped during the integrity scan (e.g. `svg`, which the renderer flags noisily) |
| `integrity.on_unrecoverable` | `enum` | `report` | A broken asset with no renderable version: `report` (log + heal-log row) or `quarantine` (also best-effort quarantine, only if still unused) |
| `content_scan.enabled` | `bool` | `false` | Required fail-closed delete/move guard for hard-coded paths in Pimcore object, nested, document, property, and classification-store text tables |
| `dependency_projection.bootstrap_live_scan` | `bool` | `true` | Allow a bounded live dependency scan only before the first indexed rebuild starts; later incomplete or failed projections always block mutation |
| `dependency_projection.bootstrap_max_sources` | `int` | `50000` | Maximum elements inspected by the bootstrap fallback; exceeding it returns the explicit `unknown` verdict and blocks mutation |
| `dependency_projection.rebuild_batch_size` | `int` | `1000` | Default sources processed by one rebuild command invocation; 1..10000 |
| `dependency_projection.deletion_fence_lease_seconds` | `int` | `900` | Seconds a delete owns an asset deletion fence before the row is reapable; must exceed the worst-case single-asset delete duration. min 300 |
| `dependency_projection.deletion_fence_reap_batch_size` | `int` | `1000` | Maximum stale deletion-fence rows reclaimed by one maintenance reaper run; 1..10000 |
| `dependency_projection.reconcile_stale_seconds` | `int` | `300` | Age (seconds) at which maintenance re-dispatches a still-dirty dependency source whose refresh message was lost (for example a deferred commit-fenced publication) and clears a stale orphan pending row; min 60 |
| `storage_snapshots.enabled` | `bool` | `true` | Enable maintenance and CLI storage snapshot capture |
| `storage_snapshots.minimum_interval_seconds` | `int` | `3600` | Minimum completed-run age before another non-forced full storage scan is allowed |
| `storage_snapshots.retention_days` | `int` | `365` | Retain completed/failed run headers and type rows for this many days; `0` disables pruning |
| `notifications.enabled` | `bool` | `false` | Notify when a completed bulk run's failure rate crosses the threshold |
| `notifications.failure_rate_threshold` | `float` | `0.5` | Failed/total ratio (0..1) at or above which a notification is sent |
| `notifications.recipient_user_ids` | `int[]` | `[]` | Pimcore backend users notified in-app through the Studio notification service (all are notified; unknown ids are skipped) |
| `notifications.recipient_group_ids` | `int[]` | `[]` | Pimcore user groups (roles) whose members are notified in-app |
| `notifications.sender_user_id` | `int` | `0` | Pimcore user the in-app notification is sent from (`0` = system) |
| `duplicates.merge_strategy` | `string` | `quarantine` | Default disposition for a duplicate merge: a registered strategy name (`quarantine`, `delete`, `isolate`, or a custom tagged one). See [Extending](extending.md#duplicate-merge-strategies) |
| `duplicates.group_scan_budget` | `int` | `5000` | Maximum duplicate groups a list page scans past natively-hidden groups before it reports `truncated: true` (min 1) |
| `duplicates.export_group_scan_budget` | `int` | `500000` | Maximum duplicate groups a CSV export scans before it stops and appends a truncation marker row (min 1) |
| `listing.scan_budget` | `int` | `5000` | Maximum raw candidate rows an authorized scan-and-fill walks past native-permission denials before it reports `truncated: true` (min 1). Covers asset search, unused, quarantine, integrity history, and the object/folder selectors for location drift and folder reorganization |
| `listing.batch_size` | `int` | `100` | Raw rows fetched per window while an authorized list page scans and fills (min 1) |
| `listing.export_max_rows` | `int` | `200000` | Maximum rows an authorized CSV export streams before it stops and appends a truncation marker row (min 1) |
| `cache.stats_ttl` | `int` | `60` | TTL (seconds) for the audit-stats cache (dashboard/metrics). `0` disables (always live) |
| `cache.unused_stats_ttl` | `int` | `300` | TTL (seconds) for the unused-asset storage-stats cache on the web endpoint. `0` disables |
| `zip.default_strategy` | `string` | `flat` | Default archive layout for downloads: `flat`, `folder`, `type`, or a custom `oronts_asset_pilot.zip_strategy` name |
| `zip.max_assets` | `int` | `1000` | Maximum number of assets packed into one download archive (min 1) |
| `zip.max_uncompressed_bytes` | `int` | `536870912` | Maximum total source bytes packed into one archive; protects HTTP/CLI memory, disk, and processing time |
| `zip.download_token_ttl` | `int` | `300` | Lifetime in seconds for a user-bound native browser download token; min 1 |

The content guard reuses a result when the same asset path is checked repeatedly within one HTTP
request or Messenger message. Symfony resets it between executions, so later work always revalidates
current Pimcore content. Element dependencies use the separate durable projection and its freshness
state.

## Rule Options

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `class` | `string` | yes | — | DataObject class name or `*` for wildcard |
| `fields` | `string[]` | no | `[]` | Field names to match. Empty = all asset fields |
| `locales` | `string[]` | no | `[]` | Locales to match for a localized asset field. Empty = any locale (and applies to non-localized fields too). A non-empty list never matches a non-localized field. `asset-pilot:validate-config` reports unknown values as failures against Pimcore's valid languages |
| `condition` | `string` | no | `null` | ExpressionLanguage condition (`object`, `asset`, `rule`, `locale` available) |
| `target_path` | `string` | yes | — | Twig path template |
| `strategy` | `enum` | no | `always` | `always`, `first_assignment`, `callback` |
| `callback` | `string` | no | `null` | Service ID (required when strategy is `callback`) |
| `priority` | `int` | no | `10` | Higher values match first |
| `enabled` | `bool` | no | `true` | Enable/disable individual rules |
| `filters.types` | `string[]` | no | `[]` | Asset types: `image`, `video`, `document`, etc. |
| `filters.min_size` | `int` | no | `null` | Minimum file size in bytes |
| `filters.max_size` | `int` | no | `null` | Maximum file size in bytes |
| `filters.extensions` | `string[]` | no | `[]` | Allowed file extensions |
| `options` | `map` | no | `{}` | Arbitrary per-rule data for custom filters/strategies |
| `actions` | `list` | no | `[]` | Post-move actions (`{type, ...}`); built-in `set_property` and `convert_format`. See [Extending](extending.md#rule-actions-do-more-than-move) |

## Removed keys

The `strategies.default` and `logging.channel` keys were removed because nothing consumed them: the
per-rule `strategy` already defaults to `always`, and the bundle logs through the standard autowired
`LoggerInterface` (a dedicated Monolog channel is configured in the host application's `monolog.yaml`,
not here). If your config still sets either key, delete it, otherwise the container will reject it as
an unrecognized option.

`audit.enabled` was removed because the operation journal is required for recovery and durable
observer delivery. `content_scan.classes` was removed because the content guard discovers all
supported Pimcore content tables. `content_scan.max_sources` was replaced by
`dependency_projection.bootstrap_max_sources` because dependency safety no longer performs a global
live scan for every mutation. Removed keys are rejected instead of being silently ignored.
