[Docs index](index.md)

# Overriding

[Extending](extending.md) is about *adding* behavior next to the defaults (a new filter, a callback
move decision, extra Twig functions). This page is about *changing* the bundle's own behavior: replacing or
wrapping a core service, swapping a default, or overriding configuration.

Every core capability is bound to an interface and registered behind a service **alias**, so there are
two clean override mechanisms, both standard Symfony:

- **Replace** — point the alias at your own implementation.
- **Decorate** — wrap the existing service and keep the original available as `.inner`.

App-level configuration loads after the bundle, so an alias or service you define in your project's
`config/services.yaml` wins over the bundle's.

## The overridable core services

| Interface (alias) | Default implementation |
|-------------------|------------------------|
| `RuleEngineInterface` | `RuleEngine` |
| `PathResolverInterface` | `TemplatePathResolver` |
| `ConditionEvaluatorInterface` | `ExpressionConditionEvaluator` |
| `NamingStrategyInterface` | `SafeNamingStrategy` |
| `AssetFilterInterface` | `CompositeFilter` |
| `AssetFieldExtractorInterface` | `AssetFieldExtractor` |
| `UnusedAssetFinderInterface` | `UnusedAssetFinder` |
| `AssetSearchServiceInterface` | `AssetSearchService` |
| `ConfidenceScorerInterface` | `ConfidenceScorer` |
| `AuditLoggerInterface` | `AuditLogger` |

All are under the `Oronts\AssetPilotBundle\` namespace.

## Replace a core service

Implement the interface, then re-point the alias in your project:

```php
// src/Asset/PrefixedNamingStrategy.php
namespace App\Asset;

use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Pimcore\Model\Asset;

final class PrefixedNamingStrategy implements NamingStrategyInterface
{
    public function generateName(Asset $asset, string $targetPath): string
    {
        return 'prod_' . $asset->getFilename();
    }
}
```

```yaml
# config/services.yaml
services:
    App\Asset\PrefixedNamingStrategy: ~

    # The bundle injects NamingStrategyInterface everywhere; re-point it.
    Oronts\AssetPilotBundle\Naming\NamingStrategyInterface: '@App\Asset\PrefixedNamingStrategy'
```

The same pattern replaces any row in the table above (your own `RuleEngine`, `AuditLogger`,
`ConfidenceScorer`, and so on).

## Decorate a core service

When you want to keep the default and only add to it, decorate. The original is injected as `.inner`:

```php
// src/Asset/NotifyingAuditLogger.php
namespace App\Asset;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: AuditLoggerInterface::class)]
final class NotifyingAuditLogger implements AuditLoggerInterface
{
    public function __construct(#[AutowireDecorated] private readonly AuditLoggerInterface $inner) {}

    public function log(MoveOperation $operation): void
    {
        $this->inner->log($operation);
        // ... emit a metric, ping a channel, etc.
    }

    // Delegate the remaining interface methods to $this->inner.
}
```

Decoration is transparent to the alias: everything that depends on `AuditLoggerInterface` now gets
your decorator with the bundle's logger inside it.

## Swap a default move strategy

A rule's `strategy` option accepts only the three built-in values (`always`, `first_assignment`,
`callback`), defaulting to `always`; there is no way to register a fourth strategy name. To change move
behavior:

- For per-rule custom logic, use `strategy: callback` with a `callback` service implementing
  `ConflictStrategyInterface`, which needs no service overrides at all (see
  [Extending — Custom Strategy](extending.md#custom-strategy) and
  [Scenarios](scenarios.md#callback-strategy-with-custom-logic)).
- To change what a built-in strategy does globally, decorate or replace its service
  (`AlwaysMoveStrategy`, `FirstAssignmentStrategy`, or `CallbackStrategy`).

## Override configuration defaults

Most behavior is configuration, not code. Set these in `config/packages/` instead of overriding
services:

- `protection.lock_property` — the property name that locks an asset (default `asset_pilot_locked`).
- `protection.exclude_folders` — folder trees never touched by organization.
- `async.enabled`, `async.batch_size` — synchronous vs queued moves and bulk batch size.
- `audit.retention_days` — how long audit rows are kept.
- `locales` — the locales scanned for localized fields.
- `confidence.recently_uploaded_days`, `confidence.probably_unused_days` — the day cutoffs for the
  unused-asset confidence buckets (used by both scoring and the `?confidence=` filter).

See [Configuration](configuration.md) for the full tree.

To change the scoring *logic* itself (not just the day cutoffs), replace the owning service:

- The confidence classification algorithm lives in `ConfidenceScorer`. Replace
  `ConfidenceScorerInterface` to change how assets are scored beyond the configurable day windows.

## Override the Twig path-resolution behavior

`TemplatePathResolver` is the default `PathResolverInterface`, a single service (not a tagged chain).
To change how target paths are built, replace that alias with your own implementation, see
[Extending — Custom Path Resolver](extending.md#custom-path-resolver). To only add filters or
functions to the existing Twig environment, tag a Twig extension instead, see
[Extending — Add Twig Filters/Functions](extending.md#add-twig-filtersfunctions-to-path-templates).
To inject extra variables into templates without replacing the resolver, tag a context provider, see
[Extending — Add Path-Template Variables](extending.md#add-path-template-variables).

## Override the Studio UI

The dashboard ships as a Module Federation remote built from `assets/studio`. To customize it, fork
that source (or build your own remote that mounts under the Asset Pilot route) and build it as
described in [Installation](installation.md#6-build-the-studio-ui-assets). The REST backend it talks
to is documented in [REST API](rest-api.md) and is stable on its own.
