[Docs index](index.md)

# Developer Experience

A map of every seam in one place, plus the local workflow. Use this to decide *where* to plug in, then
follow the linked guide for the *how*.

## Extend or override?

- **Extend** when you want behavior *alongside* the defaults: another filter, a callback move decision, extra
  Twig functions, programmatic rules, reacting to a mutation. Tag a service or listen to an event. See
  [Extending](extending.md).
- **Override** when you want to *change* a default: replace or decorate one of the core services, swap
  a default strategy, or change a value that is not configuration. See [Overriding](overriding.md).

Most consumer needs are extend, not override. Reach for override only when the default itself is wrong
for you.

## Extension points (tags)

Implement the interface and the bundle picks the service up through a tagged iterator or service
locator, without subclassing. Most bundle-owned seams are auto-tagged container-wide via
`registerForAutoconfiguration`, so they need no config edit at all. Filters, Twig extensions, and
expression-function providers are the exception: apply their tag from the table explicitly (the last
two are third-party interfaces the bundle cannot auto-tag).

| Tag | Implement | Selection | Guide |
|-----|-----------|-----------|-------|
| `oronts_asset_pilot.filter` | `AssetFilterInterface` | all composed with AND | [link](extending.md#custom-filter) |
| `oronts_asset_pilot.callback` | `CallbackDecisionInterface` | custom move decision via `strategy: callback` | [link](extending.md#custom-strategy) |
| `oronts_asset_pilot.rule_provider` | `RuleProviderInterface` | merged into the rule set | [link](extending.md#programmatic-rules) |
| `oronts_asset_pilot.context_provider` | `ContextProviderInterface` | adds variables to path templates | [link](extending.md#add-path-template-variables) |
| `oronts_asset_pilot.twig_extension` | Twig `ExtensionInterface` | added to the path-template Twig env | [link](extending.md#add-twig-filtersfunctions-to-path-templates) |
| `oronts_asset_pilot.expression_function_provider` | `ExpressionFunctionProviderInterface` | registered on the condition evaluator | [link](extending.md#add-functions-to-rule-conditions) |
| `oronts_asset_pilot.health_check` | `HealthCheckInterface` | run by `asset-pilot:health` and `GET /health` | [link](extending.md#add-a-health-check) |
| `oronts_asset_pilot.rule_action` | `RuleActionInterface` | prepared before mutation and delivered idempotently after commit | [link](extending.md#rule-actions-do-more-than-move) |
| `oronts_asset_pilot.operation_observer` | `DurableOperationObserverInterface` | persisted success/failure delivery with actor, retry, lease, and optional declared asset ACL/lock | [link](extending.md#durable-operation-observers) |
| `oronts_asset_pilot.integrity_checker` | `IntegrityCheckerInterface` | highest-priority `supports()` match render-tests the asset | [link](extending.md#add-an-integrity-checker) |
| `oronts_asset_pilot.notifier` | `NotifierInterface` | receives an immutable `Notification` with kind, severity, presentation text, and safe scalar context | [link](extending.md#add-a-notifier) |
| `oronts_asset_pilot.duplicate_merge_strategy` | `DuplicateMergeStrategyInterface` | copy disposition for merge, selected by `name()` (built-ins quarantine/delete/isolate) | [link](extending.md#duplicate-merge-strategies) |
| `oronts_asset_pilot.zip_strategy` | `ZipEntryStrategyInterface` | download-zip archive layout, selected by `getName()` (built-ins flat/folder/type) | [link](extending.md#add-a-zip-layout-strategy) |

Integrity checker implementations are additive through the tag. Selection policy is independently
replaceable or decoratable through `IntegrityCheckerResolverInterface`, so changing priority or
fallback behavior applies consistently to scans, previews, and heals.

The default path resolver is a single service, not a tagged chain; to change it, replace the
`PathResolverInterface` alias (see below and [Overriding](overriding.md)).

## Replaceable core services (aliases)

Application capabilities and advanced infrastructure are bound to public interfaces, so consumers
can replace or decorate them with normal Symfony configuration. The authoritative alias/default
table, replacement examples, decoration examples, and infrastructure invariants live in
[Overriding](overriding.md); an executable container test keeps the documented rows synchronized with
their live defaults (each documented alias must resolve to the listed implementation). The table is a
curated subset, not an exhaustive list of every service alias.

## Events

The supported extension boundaries below dispatch typed events you can subscribe to. Constants live
on `AssetPilotEvents`; maintenance-only operations such as snapshot capture, audit retention,
filename normalization, and empty-folder sweeping are intentionally not part of this event contract.

| Group | Events |
|-------|--------|
| Move pipeline | `PRE_MOVE`, `POST_MOVE`, `MOVE_FAILED` |
| Durable outcomes | `DURABLE_OPERATION_SUCCEEDED`, `DURABLE_OPERATION_FAILED` |
| Bulk runs | `BULK_STARTED`, `BULK_COMPLETED` |
| Asset mutations | `ASSET_LOCKED`, `ASSET_UNLOCKED`, `ASSET_PROPERTY_SET`, `ASSETS_TAGGED`, `UNUSED_DELETED`, `UNUSED_MOVED`, `REVERTED`, `QUARANTINED`, `RESTORED` |
| Duplicate merge | `DUPLICATE_MERGE_COMMITTED` |
| Integrity heal | `INTEGRITY_PRE_HEAL`, `INTEGRITY_POST_HEAL` |

`PRE_MOVE` can veto a move and `INTEGRITY_PRE_HEAL` can veto a version rollback. Move
events carry their `MoveOperation` and failure cause; bulk, mutation, and heal events carry the
typed payload described in the table. See
[Extending — Events](extending.md#events).

Use durable outcome events for side effects that must survive process or broker failure. Synchronous
move events remain the correct extension point for pre-move vetoes and in-process diagnostics.

## Inspect and debug

- `bin/console asset-pilot:debug-rule --object-id=ID` — per-rule trace of why each rule matched or was
  skipped, with the resolved path.
- `bin/console asset-pilot:validate-config` — validates classes, fields, condition syntax, Twig
  templates, callback registration, and filter values before you deploy.
- `bin/console asset-pilot:organize --object-id=ID -v` — full single-object evaluation without moving a file.
- `POST {studio_backend_prefix}/asset-pilot/organize/explain` — the same trace over the
  [REST API](rest-api.md#operations), used by the Studio UI.

## Local workflow

PHP (from the bundle root):

```bash
composer test       # phpunit
composer cs         # php-cs-fixer dry-run (diff)
composer cs-fix     # php-cs-fixer apply
composer stan       # phpstan (level 5)
```

Studio UI (from `assets/studio`; releases already contain a prebuilt remote, so this is a contributor
workflow; see [Contributing](../CONTRIBUTING.md)):

```bash
npm ci
npm run check-types   # tsc --noEmit
npm run build         # production Module Federation remote
npm run dev-server    # local dev server on :3040
```

Tests are kernel-free by design (protected-method seams over a Pimcore kernel); see
[Testing](testing.md).
