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

Tag a service and the bundle picks it up through a tagged iterator or service locator. No subclassing,
no config edits.

| Tag | Implement | Selection | Guide |
|-----|-----------|-----------|-------|
| `oronts_asset_pilot.filter` | `AssetFilterInterface` | all composed with AND | [link](extending.md#custom-filter) |
| `oronts_asset_pilot.callback` | `ConflictStrategyInterface` | custom move decision via `strategy: callback` | [link](extending.md#custom-strategy) |
| `oronts_asset_pilot.rule_provider` | `RuleProviderInterface` | merged into the rule set | [link](extending.md#programmatic-rules) |
| `oronts_asset_pilot.context_provider` | `ContextProviderInterface` | adds variables to path templates | [link](extending.md#add-path-template-variables) |
| `oronts_asset_pilot.twig_extension` | Twig `ExtensionInterface` | added to the path-template Twig env | [link](extending.md#add-twig-filtersfunctions-to-path-templates) |
| `oronts_asset_pilot.expression_function_provider` | `ExpressionFunctionProviderInterface` | registered on the condition evaluator | [link](extending.md#add-functions-to-rule-conditions) |
| `oronts_asset_pilot.health_check` | `HealthCheckInterface` | run by `asset-pilot:health` and `GET /health` | [link](extending.md#add-a-health-check) |
| `oronts_asset_pilot.rule_action` | `RuleActionInterface` | post-move actions selected by a rule's `actions` | [link](extending.md#rule-actions-do-more-than-move) |
| `oronts_asset_pilot.integrity_checker` | `IntegrityCheckerInterface` | highest-priority `supports()` match render-tests the asset | [link](extending.md#add-an-integrity-checker) |

The default path resolver is a single service, not a tagged chain; to change it, replace the
`PathResolverInterface` alias (see below and [Overriding](overriding.md)).

## Replaceable core services (aliases)

Each is bound to an interface alias, so it can be replaced or decorated: `RuleEngineInterface`,
`PathResolverInterface`, `ConditionEvaluatorInterface`, `NamingStrategyInterface`,
`AssetFilterInterface`, `AssetFieldExtractorInterface`, `UnusedAssetFinderInterface`,
`AssetSearchServiceInterface`, `ConfidenceScorerInterface`, `AuditLoggerInterface`. See
[Overriding](overriding.md).

## Events

Every mutation dispatches a typed event you can subscribe to. Constants live on `AssetPilotEvents`.

| Group | Events |
|-------|--------|
| Move pipeline | `PRE_MOVE`, `POST_MOVE`, `MOVE_FAILED` |
| Bulk runs | `BULK_STARTED`, `BULK_COMPLETED` |
| Asset mutations | `ASSET_LOCKED`, `ASSET_UNLOCKED`, `ASSET_PROPERTY_SET`, `ASSETS_TAGGED`, `UNUSED_DELETED`, `UNUSED_MOVED`, `REVERTED`, `QUARANTINED`, `RESTORED` |
| Integrity heal | `INTEGRITY_PRE_HEAL`, `INTEGRITY_POST_HEAL` |

`PRE_MOVE` can veto or rewrite a move and `INTEGRITY_PRE_HEAL` can veto a version rollback; the rest
are notifications carrying the `MoveOperation` / `OperationResult` / error. See
[Extending — Events](extending.md#events).

## Inspect and debug

- `bin/console asset-pilot:debug-rule --object-id=ID` — per-rule trace of why each rule matched or was
  skipped, with the resolved path.
- `bin/console asset-pilot:validate-config` — validates classes, fields, condition syntax, Twig
  templates, callback registration, and filter values before you deploy.
- `bin/console asset-pilot:organize --dry-run -v` — full evaluation without moving a file.
- `POST /pimcore-studio/api/asset-pilot/organize/explain` — the same trace over the
  [REST API](rest-api.md#operations), used by the Studio UI.

## Local workflow

PHP (from the bundle root):

```bash
composer test       # phpunit
composer cs         # php-cs-fixer dry-run (diff)
composer cs-fix     # php-cs-fixer apply
composer stan       # phpstan (level 5)
```

Studio UI (from `assets/studio`, needs a built Pimcore vendor for the local tarball, see
[Installation](installation.md#6-build-the-studio-ui-assets)):

```bash
npm ci
npm run check-types   # tsc --noEmit
npm run build         # production Module Federation remote
npm run dev-server    # local dev server on :3040
```

Tests are kernel-free by design (protected-method seams over a Pimcore kernel); see
[Testing](testing.md).
