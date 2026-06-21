[← Documentation index](index.md) · [Project README](../README.md)

# Testing

The bundle ships with a fast, pure-unit PHPUnit suite (no Pimcore kernel required, runs in well
under a second).

```bash
composer install
vendor/bin/phpunit
```

The suite covers every core component. Tests live under `tests/Unit/`, mirroring the `src/`
namespace layout:

```
tests/
├── Unit/
│   ├── Condition/          ExpressionConditionEvaluator, evaluateStrict
│   ├── Controller/         AuditController (revert guard, CSV), OperationsController parse guard
│   ├── DependencyInjection/ Configuration tree, per-rule options validation
│   ├── Engine/             RuleEngine matching and explain
│   ├── Enum/               MoveStrategy, OperationStatus, TriggerType, CollisionPattern, PropertyType
│   ├── Event/              AssetMoveEvent, AssetMutationEvent, AssetPilotEvents
│   ├── EventListener/      DataObjectSaveListener
│   ├── Filter/             Type, Size, Extension, Composite
│   ├── Message/            OrganizeAssetsMessage, BulkOrganizeMessage
│   ├── MessageHandler/     BulkOrganizeHandler
│   ├── Model/              Rule, RuleMatch, MoveOperation, OperationResult, AssetFieldInfo
│   ├── Naming/             SafeNamingStrategy collision resolution
│   ├── PathResolver/       TemplatePathResolver rendering and validation
│   ├── Service/            LoopGuard, ConfidenceScorer, SortWhitelist, AssetOrganizer seams
│   └── Strategy/           Always, FirstAssignment, Callback, ConflictResolver
└── bootstrap.php
```

Static-coupled code (controllers and services that call Pimcore static APIs) is unit-tested through
protected method seams overridden in anonymous subclasses, and through pure helper classes
(`SortWhitelist`, `ConfidenceFilter`, `Like`, `AssetSortColumns`), so the whole suite stays
kernel-free.
