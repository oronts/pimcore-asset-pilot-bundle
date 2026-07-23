[Docs index](index.md)

# Reference

A compact lookup for every public surface. Each section links to its full page.

## Package facts

| | |
|---|---|
| Composer package | `oronts/asset-pilot-bundle` |
| PHP namespace | `Oronts\AssetPilotBundle\` |
| Bundle class | `OrontsAssetPilotBundle` |
| Config root | `oronts_asset_pilot` |
| Console prefix | `asset-pilot:*` |
| REST base | `%pimcore_studio_backend.url_prefix%/asset-pilot` (default `/pimcore-studio/api/asset-pilot`) |
| Requires | PHP >= 8.4, Pimcore ^12.3.11, Studio 2025.4 LTS, Symfony components ^7.3 |

## Configuration keys

Full tree and defaults in [Configuration](configuration.md).

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `enabled` | bool | `true` | Automatic object-save and asset-upload organization switch; explicit operations remain available |
| `allowed_classes` | list | `[]` (all) | Restrict the data-object save listener to these classes |
| `locales` | list | `[]` (all valid) | Locales scanned for localized fields |
| `rules` | map | `[]` | Named rule set (see below) |
| `naming.collision_pattern` | enum | `counter` | `counter` \| `timestamp` \| `uuid` |
| `naming.slugify` | bool | `true` | Slugify generated filenames |
| `async.enabled` | bool | `true` | Queue moves via Messenger vs run synchronously |
| `async.batch_size` | int | `50` | Objects per bulk batch |
| `async.transport` | string | `asset_pilot` | Receiver required for organization and durable delivery messages |
| `async.failure_transport` | string | `asset_pilot_failed` | Failure receiver inspected by readiness health |
| `async.max_queue_depth` | int | `1000` | Queue depth that changes async health to warning |
| `async.worker_heartbeat_max_age` | int | `120` | Maximum required-consumer heartbeat age before health becomes critical |
| `idempotency.lock_ttl` | float | `60` | Renewable mutation and target-allocation lock lifetime in seconds |
| `idempotency.max_object_replays` | int | `3` | Maximum latest-state passes before a continuously changing object fails for retry |
| `operation_journal.recovery_after_seconds` | int | `900` | Stale age before unfinished move/revert reconciliation |
| `operation_journal.delivery_batch_size` | int | `100` | Due observer deliveries queued per maintenance pass |
| `operation_journal.dispatch_deduplication_seconds` | int | `3600` | Native Messenger queue deduplication TTL |
| `operation_journal.max_attempts` | int | `5` | Delivery attempts before dead-letter health failure |
| `operation_journal.base_retry_seconds` | int | `30` | Initial observer delivery retry delay |
| `operation_journal.max_retry_seconds` | int | `3600` | Observer delivery retry ceiling |
| `operation_journal.lease_seconds` | int | `300` | Durable delivery claim lease |
| `operation_runs.retention_days` | int | `90` | Retain terminal actor-scoped operation runs; active runs are never pruned |
| `operation_runs.retention_batch_size` | int | `500` | Maximum expired runs pruned in one maintenance pass (1..1000) |
| `operation_runs.lease_seconds` | int | `300` | Durable liveness lease for an in-flight operation-run item; renewed each heartbeat, expired items are failed by maintenance (min 1) |
| `operation_runs.stale_queued_warning_seconds` | int | `86400` | Queued-run age that raises a health warning (probably-lost broker message); never auto-failed (min 60) |
| `audit.retention_days` | int | `90` | Age at which `--cleanup` prunes rows |
| `protection.exclude_folders` | list | `[]` | Folder trees never organized |
| `protection.lock_property` | string | `asset_pilot_locked` | Property that locks an asset |
| `confidence.recently_uploaded_days` | int | `30` | Upper bound (days) for the `recently_uploaded` confidence bucket |
| `confidence.probably_unused_days` | int | `90` | Upper bound (days) for `probably_unused`; older scores `definitely_unused` |
| `quarantine.folder` | string | `/Quarantine` | Folder quarantined assets are moved to instead of being deleted |
| `quarantine.grace_days` | int | `30` | Days before the purge task may hard-delete a quarantined asset (if still unused) |
| `integrity.enabled` | bool | `true` | Enable broken-asset integrity detection |
| `integrity.skip_extensions` | list | `[svg]` | Extensions skipped during integrity scans |
| `integrity.on_unrecoverable` | enum | `report` | Broken asset with no renderable version: `report` (log only) or `quarantine` (best-effort, unused-only) |
| `content_scan.enabled` | bool | `false` | Required fail-closed global hard-coded-path guard for delete/move |
| `dependency_projection.bootstrap_live_scan` | bool | `true` | Permit the bounded live fallback only before indexed bootstrap begins |
| `dependency_projection.bootstrap_max_sources` | int | `50000` | Bootstrap fallback source budget; exceeding it returns `unknown` and blocks mutation |
| `dependency_projection.rebuild_batch_size` | int | `1000` | Default bounded projection rebuild batch; 1..10000 |
| `dependency_projection.deletion_fence_lease_seconds` | int | `900` | Seconds a delete owns an asset deletion fence before reaping; must exceed the worst-case single-asset delete (min 300) |
| `dependency_projection.deletion_fence_reap_batch_size` | int | `1000` | Stale deletion-fence rows reclaimed per maintenance reaper run (1..10000) |
| `dependency_projection.reconcile_stale_seconds` | int | `300` | Age at which a still-dirty dependency source (e.g. a lost commit-fenced refresh message) is re-dispatched for reconciliation and a stale orphan pending row is cleared by maintenance (min 60) |
| `storage_snapshots.enabled` | bool | `true` | Enable storage trend capture |
| `storage_snapshots.minimum_interval_seconds` | int | `3600` | Full-scan cadence guard (`0` disables) |
| `storage_snapshots.retention_days` | int | `365` | Snapshot run retention (`0` keeps all) |
| `notifications.enabled` | bool | `false` | Notify when a completed bulk run reaches the configured failure threshold |
| `notifications.failure_rate_threshold` | float | `0.5` | Failed/total ratio that triggers a notification |
| `notifications.recipient_user_ids` | list | `[]` | Pimcore users receiving in-app notifications |
| `notifications.recipient_group_ids` | list | `[]` | Pimcore groups whose members receive in-app notifications |
| `notifications.sender_user_id` | int | `0` | Pimcore notification sender (`0` = system) |
| `duplicates.merge_strategy` | string | `quarantine` | Default built-in or tagged duplicate disposition strategy |
| `cache.stats_ttl` | int | `60` | Dashboard/audit-stat cache lifetime; `0` disables caching |
| `cache.unused_stats_ttl` | int | `300` | Unused-storage-stat cache lifetime; `0` disables caching |
| `zip.default_strategy` | string | `flat` | Built-in or tagged archive layout strategy |
| `zip.max_assets` | int | `1000` | Maximum assets in one archive |
| `zip.max_uncompressed_bytes` | int | `536870912` | Maximum total source bytes in one archive |
| `zip.download_token_ttl` | int | `300` | Lifetime in seconds of a prepared archive download token |

### Rule options

| Option | Type | Default | Purpose |
|--------|------|---------|---------|
| `class` | string | required | DataObject class the rule targets |
| `fields` | list | `[]` (all) | Asset fields to read |
| `condition` | string | `null` | ExpressionLanguage condition |
| `target_path` | string | required | Twig path template |
| `strategy` | enum | `always` | `always` \| `first_assignment` \| `callback` |
| `callback` | string | `null` | Service id (required for `callback`) |
| `priority` | int | `10` | Higher wins when rules overlap |
| `enabled` | bool | `true` | Toggle the rule |
| `filters` | map | `{}` | `types`, `min_size`, `max_size`, `extensions` |
| `options` | map | `{}` | Arbitrary per-rule data for custom code |
| `actions` | list | `[]` | Post-move actions (`{type, ...}`); built-in `set_property`. See [Extending](extending.md#rule-actions-do-more-than-move) |

## Commands

Full flags in [Commands](commands.md).

| Command | Purpose |
|---------|---------|
| `asset-pilot:organize` | Preview objects; apply the exact signed selection with `--apply --plan-token=...` (`--class`, `--object-id`, `--async`, `--batch-size`) |
| `asset-pilot:status` | Configured rules and statistics (`--format=json`) |
| `asset-pilot:audit` | Browse / filter / `--cleanup` the audit log (`--asset-id`, `--object-id`, `--class`, `--rule`, `--status`) |
| `asset-pilot:cleanup-unused` | Preview unused cleanup; apply the exact signed selection with `--apply --plan-token=...` (`--confirm-delete` for deletion, plus an explicit filter, `--by-ids`, or `--all`) |
| `asset-pilot:validate-config` | Validate rules, conditions, templates, callbacks, filters |
| `asset-pilot:debug-rule` | Per-rule evaluation trace for an object/asset |
| `asset-pilot:rules-export` | Export the rule set as a portable JSON/YAML artifact |
| `asset-pilot:rules-diff` | Diff an artifact against the current rules (`--fail-on-diff`) |
| `asset-pilot:rule-overlap` | Report rules competing for the same assets |
| `asset-pilot:verify-locations` | Report assets not at their rule-expected path (`--class` or `--object-id`) |
| `asset-pilot:replay-failures` | Preview failed objects; apply the exact preview with `--apply --plan-token=...` (`--object-id`, `--since`, `--rule`, `--class`, `--limit`, `--async`) |
| `asset-pilot:recover-operations` | Preview stale move/revert journal state; finalize exact reviewed IDs with `--apply --plan-token=...` |
| `asset-pilot:retry-deliveries` | Preview dead durable observer deliveries; requeue the exact reviewed rows with `--apply --plan-token=...` |
| `asset-pilot:health` | Run health checks (exits non-zero on a CRITICAL check) |
| `asset-pilot:metrics` | Output metrics as Prometheus text exposition or JSON (`--format`) |
| `asset-pilot:capture-storage-snapshot` | Record an unused-storage snapshot for trend reporting (`--force` bypasses the cadence guard) |
| `asset-pilot:reorganize-assets` | Preview owners in a folder or by id; apply the exact preview with `--apply --plan-token=...` (`--folder`, `--by-ids`, `--limit`, `--async`) |
| `asset-pilot:quarantine-purge` | Preview expired quarantined assets; purge the exact signed selection with `--apply --plan-token=...` (`--grace-days`) |
| `asset-pilot:sweep-empty-folders` | Preview empty asset folders; remove the exact signed selection with `--apply --plan-token=...` (`--folder`, `--limit`) |
| `asset-pilot:normalize-filenames` | Preview filename sanitization; apply the exact signed selection with `--apply --plan-token=...` (`--by-ids`, `--folder`, `--type`, `--extension`, `--limit`) |
| `asset-pilot:find-duplicates` | Report byte-identical assets (`--scan` to build the hash index, `--folder`, `--type`, `--extension`, `--limit` to cap indexed assets, `--report-limit` to cap reported groups, or `--asset-id` for one asset's group) |
| `asset-pilot:merge-duplicates` | Preview a byte-identical merge; apply the exact signed selection with `--apply --plan-token=...` (`--checksum`, `--canonical`, `--strategy`), or resume a persisted run with `--run-id --apply` |
| `asset-pilot:check-integrity` | Detect assets whose binary no longer renders (`--by-ids`, `--folder`, `--type`, `--limit`) |
| `asset-pilot:heal-assets` | Preview healing/undo; apply the exact signed selection with `--apply --plan-token=...` (`--by-ids`, `--undo`, scan filters, or explicit `--all`) |
| `asset-pilot:download-zip` | Build a zip of assets to a file for cron/workers (`--asset-ids`/`--folder-id`/`--object-ids`, `--non-recursive`, `--strategy`, `--thumbnail`, `--output`, `--force`) |

## REST endpoints

Full request/response shapes are in [REST API](rest-api.md). All paths are under the REST base. The
table lists permissions explicitly because storage trends, integrity history and undo, duplicate merge, and tag
operations have stricter requirements than the usual View/Operate split (see [Permissions](permissions.md)).

| Method | Path | Permission |
|--------|------|------------|
| GET | `/dashboard`, `/dashboard/class-stats`, `/health`, `/health/readiness`, `/metrics`, `/integrity`, `/duplicates`, `/duplicates/strategies`, `/duplicates/export` | View |
| GET | `/rules`, `/rules/{name}`, `/rules/{name}/preview`, `/rules/export`, `/rules/overlap`, `/rules/drift` | View |
| POST | `/rules/diff` | View |
| POST | `/rules/{name}/apply` | Operate |
| POST | `/integrity/heal` | Operate |
| GET | `/integrity/history` | Admin |
| POST | `/integrity/undo` | Admin |
| POST | `/duplicates/merge` | Admin |
| GET | `/folders/empty` | View |
| GET | `/storage/trends` | Admin |
| POST | `/folders/empty/delete` | Operate |
| POST | `/organize`, `/organize/bulk` | Operate |
| POST | `/organize/explain`, `/operations/bulk-preview` | View |
| POST | `/operations/replay`, `/operations/reorganize` | Operate |
| GET | `/operations/status` | View |
| GET | `/operations/runs`, `/operations/runs/{id}` | View |
| POST | `/operations/runs/{id}/cancel`, `/operations/runs/{id}/retry` | Operate |
| GET | `/audit`, `/audit/export` | View |
| POST | `/audit/{id}/revert` | Admin |
| GET | `/unused-assets`, `/unused-assets/stats`, `/unused-assets/export` | View |
| POST | `/unused-assets/bulk-delete`, `/unused-assets/bulk-move`, `/unused-assets/bulk-quarantine` | Operate |
| GET | `/quarantine`, `/quarantine/export` | View |
| POST | `/quarantine/{assetId}/restore` | Operate |
| GET | `/assets/search` | View |
| GET | `/assets/tags` | View + Pimcore `tags_assignment` |
| POST | `/assets/bulk-tag` | Operate + Pimcore `tags_assignment` |
| POST | `/assets/bulk-property` | Operate |
| POST | `/assets/download-zip` | View |
| POST | `/assets/download-zip/prepare` | View |
| GET | `/assets/download-zip/{token}` | View |
| POST / DELETE | `/assets/{id}/lock` | Operate |

Unused-asset bulk mutations use a two-step contract. Send `dryRun: true` first, then repeat the
exact request with `dryRun: false` and the returned `planToken`. Tokens are actor-bound,
single-use, short-lived, and rejected if an asset changes after preview.

`POST /operations/reorganize` and `POST /operations/replay` follow the same two-step contract and
preview by default. Their token binds the complete selector, resolved object set, actor,
configuration, object fingerprints, and computed move operations. Apply creates one operation run;
async responses include its `runId` and `statusUrl`.

`POST /integrity/heal` also requires two calls: preview with `dryRun: true`, then apply the same ID
set with the returned `planToken`. Its token additionally binds the sorted IDs, effective integrity
configuration, selected checker and exact heal result, live checksum, protection state, and version
descriptors. The entire batch is locked and revalidated before any restore. This HTTP requirement
matches the `asset-pilot:heal-assets` CLI requirement to preview first and provide the returned
token with `--apply --plan-token=...`.

`POST /duplicates/merge` also uses a signed two-step plan. Preview with `dryRun: true`; apply the
same checksum, canonical asset, and strategy with the returned `planToken`. Apply creates a durable
operation run whose item state records reference-repoint and disposition phases. A later request
containing only that actor-scoped `runId` resumes an interrupted run without repeating a committed
phase. Retryable blocked, failed, or cancelled run items are also eligible through
`POST /operations/runs/{id}/retry`; duplicate-merge retries resume through the same phase-aware
service. Skipped items are terminal records of deliberate non-execution and are not retried.

## Events

Constants on `AssetPilotEvents`. Details in [DX](dx.md#events) and
[Extending](extending.md#events).

`PRE_MOVE`, `POST_MOVE`, `MOVE_FAILED`, `DURABLE_OPERATION_SUCCEEDED`,
`DURABLE_OPERATION_FAILED`, `BULK_STARTED`, `BULK_COMPLETED`, `ASSET_LOCKED`,
`ASSET_UNLOCKED`, `ASSET_PROPERTY_SET`, `ASSETS_TAGGED`, `UNUSED_DELETED`, `UNUSED_MOVED`, `REVERTED`,
`QUARANTINED`, `RESTORED`, `DUPLICATE_MERGE_COMMITTED`, `INTEGRITY_PRE_HEAL` (cancellable),
`INTEGRITY_POST_HEAL`.

## Enums

`Oronts\AssetPilotBundle\Enum\`.

| Enum | Values |
|------|--------|
| `AssetPilotPermission` | `asset_pilot_view`, `asset_pilot_operate`, `asset_pilot_admin` |
| `MoveStrategy` | `always`, `first_assignment`, `callback` |
| `CollisionPattern` | `counter`, `timestamp`, `uuid` |
| `PropertyType` | `text`, `bool`, `select` |
| `OperationStatus` | `pending`, `in_progress`, `recovery_required`, `completed`, `completed_with_observer_error`, `failed`, `skipped` |
| `TriggerType` | `object_save`, `bulk_operation`, `manual`, `scheduled`, `api`, `asset_upload` |
| `ConfidenceLevel` | `protected`, `historically_used`, `recently_uploaded`, `probably_unused`, `definitely_unused` |
| `ActorType` | `user`, `system`, `anonymous` |
| `ApplyPlanStatus` | `Valid`, `Claimed`, `Malformed`, `Stale`, `AlreadyClaimed` |
| `BulkObjectStatus` | `succeeded`, `skipped`, `failed` |
| `DispositionOutcome` | `quarantined`, `deleted`, `left_referenced`, `left_error`, `blocked`, `skipped` |
| `DriftEligibility` | `no_known_block`, `blocked`, `runtime_check_required` |
| `DuplicateMergePhase` | `prepared`, `repointing`, `repointed`, `disposing`, `committed`, `blocked`, `failed` |
| `HealOutcome` | `healed`, `already_renderable`, `unrecoverable`, `unverifiable`, `skipped` |
| `HealthStatus` | `ok`, `warning`, `critical` |
| `IntegrityStatus` | `renderable`, `broken`, `unverifiable` |
| `OperationDeliveryOutcome` | `success`, `failure` |
| `OperationDeliveryStatus` | `prepared`, `pending`, `processing`, `retry`, `delivered`, `dead`, `cancelled` |
| `OperationKind` | `move`, `revert` |
| `OperationRunItemStatus` | `queued`, `running`, `completed`, `blocked`, `skipped`, `failed`, `cancelled` |
| `OperationRunStatus` | `queued`, `running`, `cancel_requested`, `cancelled`, `completed`, `blocked`, `partial`, `failed` |
| `QuarantineStatus` | `pending`, `committed` |
| `RevertFailure` | `audit_entry_not_found`, `not_completed`, `asset_not_found`, `permission_denied`, `path_conflict`, `execution_failed`, `recovery_required` |
| `ReviewedSelectionError` | `SelectionTooLarge`, `MutationForbidden`, `MissingPlanToken`, `MalformedPlanToken`, `StalePlan`, `PreflightFailed`, `ExecutionFailed` |
| `RulePreviewPlanStatus` | `Valid`, `Malformed`, `Stale` |
| `UndoHealOutcome` | `would_reverse`, `reversed`, `skipped`, `failed` |
| `UndoHealReason` | `already_undone`, `superseded`, `asset_busy`, `asset_not_found`, `not_permitted`, `asset_locked`, `excluded_folder`, `no_reversible_heal`, `version_missing`, `asset_changed`, `restore_failed`, `log_update_failed` |

## Extension tags

Tag a service to plug in. See [DX](dx.md#extension-points-tags) and [Extending](extending.md).

| Tag | Interface |
|-----|-----------|
| `oronts_asset_pilot.filter` | `AssetFilterInterface` |
| `oronts_asset_pilot.callback` | `CallbackDecisionInterface` (used via `strategy: callback`) |
| `oronts_asset_pilot.rule_provider` | `RuleProviderInterface` |
| `oronts_asset_pilot.context_provider` | `ContextProviderInterface` |
| `oronts_asset_pilot.twig_extension` | Twig `ExtensionInterface` |
| `oronts_asset_pilot.expression_function_provider` | `ExpressionFunctionProviderInterface` |
| `oronts_asset_pilot.health_check` | `HealthCheckInterface` |
| `oronts_asset_pilot.rule_action` | `RuleActionInterface` (post-move actions, built-in `set_property`) |
| `oronts_asset_pilot.operation_observer` | `DurableOperationObserverInterface` (prepare before mutation, deliver after outcome, declare any required asset permission) |
| `oronts_asset_pilot.integrity_checker` | `IntegrityCheckerInterface` (broken-asset detection; built-ins stream/image/document) |
| `oronts_asset_pilot.notifier` | `NotifierInterface` (typed `Notification` transport; built-in Pimcore in-app notifier) |
| `oronts_asset_pilot.duplicate_merge_strategy` | `DuplicateMergeStrategyInterface` (copy disposition for merge; built-ins quarantine/delete/isolate; `disposeCopy()` takes a mandatory `DuplicateMergeContextInterface`, optional `ResumableDuplicateMergeStrategyInterface` for resume) |
| `oronts_asset_pilot.zip_strategy` | `ZipEntryStrategyInterface` (download-zip archive layout; built-ins flat/folder/type) |

The default path resolver is a single replaceable service (`PathResolverInterface`, below), not a
tagged chain.

## Replaceable services

Interface aliases you can replace or decorate, see [Overriding](overriding.md):
`RuleEngineInterface`, `PathResolverInterface`, `ConditionEvaluatorInterface`,
`NamingStrategyInterface`, `AssetFilterInterface`, `IntegrityCheckerResolverInterface`, `AssetFieldExtractorInterface`,
`UnusedAssetFinderInterface`, `AssetSearchServiceInterface`, `AssetZipServiceInterface`,
`ConfidenceScorerInterface`, `AuditWriterInterface`, `AuditQueryInterface`,
`AuditExportInterface`, `AuditRetentionInterface`, `AssetOrganizerInterface`,
`MovePlannerInterface`, `OrganizeDispatcherInterface`, `OperationJournalInterface`,
`ApplyPlanClaimStoreInterface`, `ApplyPlanServiceInterface`, `OperationRunStoreInterface`,
`OperationRunExecutorInterface`, `OperationRunRetentionInterface`,
`ReviewedObjectOperationServiceInterface`, `OperationDeliveryStoreInterface`,
`OperationDeliveryProcessorInterface`, `OperationDeliveryDispatcherInterface`, `OperationRecoveryCoordinatorInterface`,
`OperationDeliveryRetryCoordinatorInterface`, `DependencyProjectionInterface`,
`DependencyProjectionFreshnessInterface`, `DependencyProjectionRebuilderInterface`,
`DependencyUsageVerifierInterface`, `UndoHealEligibilityProbeInterface`, and
`ProjectionMarkerConnectionInterface` (supplies the autocommit sidecar connection that keeps the
deletion-fence handshake correct when a consumer wraps an asset-referencing save in its own
database transaction).

## Template & condition helpers

- Twig path-template filters and functions: [Path Templates](path-templates.md).
- ExpressionLanguage condition functions: [Conditions](conditions.md).
