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

## Commands

Full flags in [Commands](commands.md).

| Command | Purpose |
|---------|---------|
| `asset-pilot:organize` | Organize objects (`--class`, `--object-id`, `--dry-run`, `--async`, `--batch-size`) |
| `asset-pilot:status` | Configured rules and statistics (`--json`) |
| `asset-pilot:audit` | Browse / filter / `--cleanup` the audit log |
| `asset-pilot:cleanup-unused` | Delete or move unused assets (`--dry-run`, `--action`, `--move-to`) |
| `asset-pilot:validate-config` | Validate rules, conditions, templates, callbacks, filters |
| `asset-pilot:debug-rule` | Per-rule evaluation trace for an object/asset |
| `asset-pilot:rules-export` | Export the rule set as a portable JSON/YAML artifact |
| `asset-pilot:rules-diff` | Diff an artifact against the current rules (`--fail-on-diff`) |
| `asset-pilot:rule-overlap` | Report rules competing for the same assets |
| `asset-pilot:verify-locations` | Report assets not at their rule-expected path (`--class`) |
| `asset-pilot:replay-failures` | Re-run failed objects (`--since`, `--rule`, `--class`, `--async`) |
| `asset-pilot:health` | Run health checks (exits non-zero on a CRITICAL check) |

## REST endpoints

Full request/response shapes in [REST API](rest-api.md). All under the REST base; read = View,
mutating = Operate, revert = Admin (see [Permissions](permissions.md)).

| Method | Path | Permission |
|--------|------|------------|
| GET | `/dashboard`, `/dashboard/class-stats`, `/health` | View |
| GET | `/rules`, `/rules/{name}`, `/rules/{name}/preview`, `/rules/export`, `/rules/overlap`, `/rules/drift` | View |
| POST | `/rules/diff` | View |
| POST | `/rules/{name}/apply` | Operate |
| POST | `/organize`, `/organize/bulk` | Operate |
| POST | `/organize/preview`, `/organize/explain`, `/operations/bulk-preview` | View |
| POST | `/operations/replay` | Operate |
| GET | `/operations/status` | View |
| GET | `/audit`, `/audit/stats`, `/audit/export`, `/audit/by-rule/{ruleName}/assets` | View |
| POST | `/audit/{id}/revert` | Admin |
| GET | `/unused-assets`, `/unused-assets/stats` | View |
| POST | `/unused-assets/bulk-delete`, `/unused-assets/bulk-move` | Operate |
| GET | `/assets/search`, `/assets/by-object/{objectId}`, `/assets/tags`, `/assets/{id}/tags` | View |
| POST | `/assets/bulk-tag`, `/assets/bulk-property` | Operate |
| POST / DELETE | `/assets/{id}/lock` | Operate |
| GET | `/permissions` | any authenticated user (reports the caller's own flags) |

## Events

Constants on `AssetPilotEvents`. Details in [DX](dx.md#events) and
[Extending](extending.md#events).

`PRE_MOVE`, `POST_MOVE`, `MOVE_FAILED`, `BULK_STARTED`, `BULK_COMPLETED`, `ASSET_LOCKED`,
`ASSET_UNLOCKED`, `ASSET_PROPERTY_SET`, `ASSETS_TAGGED`, `UNUSED_DELETED`, `UNUSED_MOVED`, `REVERTED`.

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
