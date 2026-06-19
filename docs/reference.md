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
| REST base | `/pimcore-studio/api/asset-pilot` |
| Requires | PHP >= 8.4, Pimcore ^12.0, Symfony Messenger/Lock ^7.3 |

## Configuration keys

Full tree and defaults in [Configuration](configuration.md).

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `enabled` | bool | `true` | Master switch for the bundle |
| `allowed_classes` | list | `[]` (all) | Restrict the asset-upload listener to these classes |
| `locales` | list | `[]` (all valid) | Locales scanned for localized fields |
| `rules` | map | `[]` | Named rule set (see below) |
| `naming.collision_pattern` | enum | `counter` | `counter` \| `timestamp` \| `uuid` |
| `naming.slugify` | bool | `true` | Slugify generated filenames |
| `async.enabled` | bool | `true` | Queue moves via Messenger vs run synchronously |
| `async.batch_size` | int | `50` | Objects per bulk batch |
| `audit.enabled` | bool | `true` | Write the audit log |
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
| `content_scan.enabled` | bool | `false` | Opt-in delete/move guard: scan text fields for a hard-coded path reference the dependency table misses |
| `content_scan.classes` | list | `[]` | Classes whose `wysiwyg`/`textarea`/`input` fields the guard scans |

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
| `asset-pilot:organize` | Organize objects (`--class`, `--object-id`, `--dry-run`, `--async`, `--batch-size`) |
| `asset-pilot:status` | Configured rules and statistics (`--json`) |
| `asset-pilot:audit` | Browse / filter / `--cleanup` the audit log (`--asset-id`, `--object-id`, `--class`, `--rule`, `--status`) |
| `asset-pilot:cleanup-unused` | Delete or move unused assets (`--by-ids`, `--dry-run`, `--action`, `--move-to`) |
| `asset-pilot:validate-config` | Validate rules, conditions, templates, callbacks, filters |
| `asset-pilot:debug-rule` | Per-rule evaluation trace for an object/asset |
| `asset-pilot:rules-export` | Export the rule set as a portable JSON/YAML artifact |
| `asset-pilot:rules-diff` | Diff an artifact against the current rules (`--fail-on-diff`) |
| `asset-pilot:rule-overlap` | Report rules competing for the same assets |
| `asset-pilot:verify-locations` | Report assets not at their rule-expected path (`--class` or `--object-id`) |
| `asset-pilot:replay-failures` | Re-run failed objects (`--object-id`, `--since`, `--rule`, `--class`, `--async`) |
| `asset-pilot:health` | Run health checks (exits non-zero on a CRITICAL check) |
| `asset-pilot:metrics` | Output metrics as Prometheus text exposition or JSON (`--format`) |
| `asset-pilot:capture-storage-snapshot` | Record an unused-storage snapshot for trend reporting |
| `asset-pilot:reorganize-assets` | Re-organize the owners of assets in a folder or by id (`--folder`, `--by-ids`, `--async`) |
| `asset-pilot:quarantine-purge` | Hard-delete quarantined assets past the grace period (`--grace-days`, `--dry-run`) |
| `asset-pilot:sweep-empty-folders` | Find / `--delete` empty asset folders (`--folder`, `--limit`) |
| `asset-pilot:normalize-filenames` | Preview / `--apply` filename sanitization (`--by-ids`, `--folder`, `--type`, `--limit`) |
| `asset-pilot:find-duplicates` | Report byte-identical assets (`--scan` to build the hash index, `--folder`, `--type`, `--limit`, or `--asset-id` for one asset's group) |
| `asset-pilot:merge-duplicates` | Merge a byte-identical group onto a canonical asset (`--checksum`, `--canonical`, `--strategy`, `--apply`; preview by default) |
| `asset-pilot:check-integrity` | Detect assets whose binary no longer renders (`--by-ids`, `--folder`, `--type`, `--limit`) |
| `asset-pilot:heal-assets` | Roll broken assets back to the last renderable version (`--by-ids`, `--dry-run`, `--undo`, scan filters) |

## REST endpoints

Full request/response shapes in [REST API](rest-api.md). All under the REST base; read = View,
mutating = Operate, revert = Admin (see [Permissions](permissions.md)).

| Method | Path | Permission |
|--------|------|------------|
| GET | `/dashboard`, `/dashboard/class-stats`, `/health`, `/metrics`, `/integrity`, `/duplicates`, `/duplicates/strategies` | View |
| GET | `/rules`, `/rules/{name}`, `/rules/{name}/preview`, `/rules/export`, `/rules/overlap`, `/rules/drift` | View |
| POST | `/rules/diff` | View |
| POST | `/rules/{name}/apply` | Operate |
| POST | `/integrity/heal` | Operate |
| POST | `/integrity/undo` | Admin |
| POST | `/duplicates/merge` | Admin |
| GET | `/folders/empty`, `/storage/trends` | View |
| POST | `/folders/empty/delete` | Operate |
| POST | `/organize`, `/organize/bulk` | Operate |
| POST | `/organize/preview`, `/organize/explain`, `/operations/bulk-preview` | View |
| POST | `/operations/replay` | Operate |
| GET | `/operations/status` | View |
| GET | `/audit`, `/audit/stats`, `/audit/export`, `/audit/by-rule/{ruleName}/assets` | View |
| POST | `/audit/{id}/revert` | Admin |
| GET | `/unused-assets`, `/unused-assets/stats` | View |
| POST | `/unused-assets/bulk-delete`, `/unused-assets/bulk-move`, `/unused-assets/bulk-quarantine` | Operate |
| GET | `/quarantine` | View |
| POST | `/quarantine/{assetId}/restore` | Operate |
| GET | `/assets/search`, `/assets/by-object/{objectId}`, `/assets/tags`, `/assets/{id}/tags` | View |
| POST | `/assets/bulk-tag`, `/assets/bulk-property` | Operate |
| POST / DELETE | `/assets/{id}/lock` | Operate |
| GET | `/permissions` | any authenticated user (reports the caller's own flags) |

## Events

Constants on `AssetPilotEvents`. Details in [DX](dx.md#events) and
[Extending](extending.md#events).

`PRE_MOVE`, `POST_MOVE`, `MOVE_FAILED`, `BULK_STARTED`, `BULK_COMPLETED`, `ASSET_LOCKED`,
`ASSET_UNLOCKED`, `ASSET_PROPERTY_SET`, `ASSETS_TAGGED`, `UNUSED_DELETED`, `UNUSED_MOVED`, `REVERTED`,
`QUARANTINED`, `RESTORED`, `INTEGRITY_PRE_HEAL` (cancellable), `INTEGRITY_POST_HEAL`.

## Enums

`Oronts\AssetPilotBundle\Enum\`.

| Enum | Values |
|------|--------|
| `AssetPilotPermission` | `asset_pilot_view`, `asset_pilot_operate`, `asset_pilot_admin` |
| `MoveStrategy` | `always`, `first_assignment`, `callback` |
| `CollisionPattern` | `counter`, `timestamp`, `uuid` |
| `PropertyType` | `text`, `bool`, `select` |
| `OperationStatus` | `pending`, `in_progress`, `completed`, `failed`, `skipped` |
| `TriggerType` | `object_save`, `bulk_operation`, `manual`, `scheduled`, `api`, `asset_upload` |
| `ConfidenceLevel` | `protected`, `historically_used`, `recently_uploaded`, `probably_unused`, `definitely_unused` |

## Extension tags

Tag a service to plug in. See [DX](dx.md#extension-points-tags) and [Extending](extending.md).

| Tag | Interface |
|-----|-----------|
| `oronts_asset_pilot.filter` | `AssetFilterInterface` |
| `oronts_asset_pilot.callback` | `ConflictStrategyInterface` (used via `strategy: callback`) |
| `oronts_asset_pilot.rule_provider` | `RuleProviderInterface` |
| `oronts_asset_pilot.context_provider` | `ContextProviderInterface` |
| `oronts_asset_pilot.twig_extension` | Twig `ExtensionInterface` |
| `oronts_asset_pilot.expression_function_provider` | `ExpressionFunctionProviderInterface` |
| `oronts_asset_pilot.health_check` | `HealthCheckInterface` |
| `oronts_asset_pilot.rule_action` | `RuleActionInterface` (post-move actions, built-in `set_property`) |
| `oronts_asset_pilot.integrity_checker` | `IntegrityCheckerInterface` (broken-asset detection; built-ins stream/image/document) |
| `oronts_asset_pilot.notifier` | `NotifierInterface` (alert transport; built-in Pimcore in-app notifier) |

The default path resolver is a single replaceable service (`PathResolverInterface`, below), not a
tagged chain.

## Replaceable services

Interface aliases you can replace or decorate, see [Overriding](overriding.md):
`RuleEngineInterface`, `PathResolverInterface`, `ConditionEvaluatorInterface`,
`NamingStrategyInterface`, `AssetFilterInterface`, `AssetFieldExtractorInterface`,
`UnusedAssetFinderInterface`, `AssetSearchServiceInterface`, `ConfidenceScorerInterface`,
`AuditLoggerInterface`.

## Template & condition helpers

- Twig path-template filters and functions: [Path Templates](path-templates.md).
- ExpressionLanguage condition functions: [Conditions](conditions.md).
