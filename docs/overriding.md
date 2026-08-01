[Docs index](index.md)

# Overriding

[Extending](extending.md) is about *adding* behavior next to the defaults (a new filter, a callback
move decision, extra Twig functions). This page is about *changing* the bundle's own behavior: replacing or
wrapping a core service, swapping a default, or overriding configuration.

Supported replaceable core services are bound to interfaces and registered behind service **aliases**, so there are
two clean override mechanisms, both standard Symfony:

- **Replace**: point the alias at your own implementation.
- **Decorate**: wrap the existing service and keep the original available as `.inner`.

App-level configuration loads after the bundle, so an alias or service you define in your project's
`config/services.yaml` wins over the bundle's. Public defaults are ordinary classes where implementation
specialization is useful, but consumers should target the smaller interface contract. Replacement and decoration
therefore work across HTTP, CLI, listeners, and workers without depending on protected implementation details.
Only immutable values and invariant helpers such as token signing, lock scope, canonical snapshots, and schema
queries are closed with `final` or private state-transition methods.

Prefer the interface seams and service decoration for customization: implement or decorate a published
interface rather than subclassing a safety service to override a step. The apply-plan token service, the
synchronous run executor, the reviewed-asset lock coordinator, the operation run store, and the
dependency-projection listener are `final`. The loop guard, run-item lease, and the deletion and claim
fences keep their token signing, lock acquisition, and fence-handshake steps private, and they are replaced
through their contract, not subclassed. Several service defaults stay ordinary (non-final) classes so their
behavior can be specialized in a subclass, but their crypto, authorization, lock-scope, fence, lease, and
state-transition steps are private on those classes too, so no enforcement decision is reachable from a
subclass. Where a class exposes a protected method it is a harmless test or template seam (a clock, an
element lookup, a render helper), never a safety decision.


## The overridable core services

| Interface (alias) | Default implementation |
|-------------------|------------------------|
| `RuleEngineInterface` | `RuleEngine` |
| `PathResolverInterface` | `TemplatePathResolver` |
| `ConditionEvaluatorInterface` | `ExpressionConditionEvaluator` |
| `NamingStrategyInterface` | `SafeNamingStrategy` |
| `ElementAuthorizationInterface` | `ElementAuthorization` |
| `AssetFilterInterface` | `CompositeFilter` |
| `AssetFieldExtractorInterface` | `AssetFieldExtractor` |
| `AssetDependencyResolverInterface` | `AssetDependencyResolver` |
| `AssetDependencyTargetExtractorInterface` | `AssetDependencyTargetExtractor` |
| `UnusedAssetFinderInterface` | `UnusedAssetFinder` |
| `AssetSearchServiceInterface` | `AssetSearchService` |
| `ConfidenceScorerInterface` | `ConfidenceScorer` |
| `AssetIntegrityServiceInterface` | `AssetIntegrityService` |
| `IntegrityCheckerResolverInterface` | `CompositeIntegrityChecker` |
| `HealthCheckerInterface` | `HealthChecker` |
| `NotificationDispatcherInterface` | `NotificationDispatcher` |
| `AssetMetadataMutationServiceInterface` | `AssetMetadataMutationService` |
| `AssetPropertyServiceInterface` | `AssetPropertyService` |
| `AssetReorganizerInterface` | `AssetReorganizer` |
| `ConfigValidatorInterface` | `ConfigValidator` |
| `ContentUsageScannerInterface` | `ContentUsageScanner` |
| `DuplicateDetectionServiceInterface` | `DuplicateDetectionService` |
| `DuplicateMergeServiceInterface` | `DuplicateMergeService` |
| `DuplicateReferenceRepointerInterface` | `DuplicateReferenceRepointer` |
| `EmptyFolderSweepServiceInterface` | `EmptyFolderSweepService` |
| `FailureReplayServiceInterface` | `FailureReplayService` |
| `IntegrityHealHistoryServiceInterface` | `IntegrityHealHistoryService` |
| `LocationDriftServiceInterface` | `LocationDriftService` |
| `VisibleObjectSelectorInterface` | `VisibleObjectSelector` |
| `MetricsServiceInterface` | `MetricsService` |
| `NormalizeFilenamesServiceInterface` | `NormalizeFilenamesService` |
| `ObjectSaveDrainInterface` | `ObjectSaveDrain` |
| `OperationReverterInterface` | `OperationReverter` |
| `PrometheusFormatterInterface` | `PrometheusFormatter` |
| `QuarantineServiceInterface` | `QuarantineService` |
| `RuleOverlapAnalyzerInterface` | `RuleOverlapAnalyzer` |
| `RulePortabilityInterface` | `RulePortability` |
| `StorageTrendServiceInterface` | `StorageTrendService` |
| `AuditWriterInterface` | `AuditLogger` |
| `AuditQueryInterface` | `AuditLogger` |
| `AuditExportInterface` | `AuditLogger` |
| `AuditRetentionInterface` | `AuditLogger` |
| `OperationJournalInterface` | `OperationJournal` |
| `ApplyPlanClaimStoreInterface` | `DbalApplyPlanClaimStore` |
| `OperationDeliveryStoreInterface` | `OperationDeliveryStore` |
| `OperationDeliveryProcessorInterface` | `OperationDeliveryProcessor` |
| `OperationDeliveryDispatcherInterface` | `OperationDeliveryDispatcher` |
| `AssetOrganizerInterface` | `AssetOrganizer` |
| `MovePlannerInterface` | `MovePlanner` |
| `OrganizeDispatcherInterface` | `OrganizeDispatcher` |
| `AssetZipServiceInterface` | `AssetZipService` |
| `ApplyPlanServiceInterface` | `ApplyPlanService` |
| `RulePreviewPlanServiceInterface` | `RulePreviewPlanService` |
| `ZipDownloadTokenStoreInterface` | `ZipDownloadTokenStore` |
| `OperationRunStoreInterface` | `OperationRunStore` |
| `OperationRunExecutorInterface` | `OperationRunExecutor` |
| `OperationRunRetentionInterface` | `OperationRunRetention` |
| `ReviewedObjectOperationServiceInterface` | `ReviewedObjectOperationService` |
| `OperationRecoveryCoordinatorInterface` | `OperationRecoveryCoordinator` |
| `OperationDeliveryRetryCoordinatorInterface` | `OperationDeliveryRetryCoordinator` |
| `DependencyProjectionInterface` | `DbalDependencyProjection` |
| `DependencyProjectionFreshnessInterface` | `DbalDependencyProjectionFreshness` |
| `DependencyProjectionRebuilderInterface` | `DependencyProjectionRebuilder` |
| `DependencyUsageVerifierInterface` | `DependencyUsageVerifier` |
| `UndoHealEligibilityProbeInterface` | `VersionRollbackHealer` |
| `VersionRollbackHealerInterface` | `VersionRollbackHealer` |
| `ApiDateFormatterInterface` | `ApiDateFormatter` |

All are under the `Oronts\AssetPilotBundle\` namespace.

ZIP service replacements return the immutable `ZipBuildResult`; its typed `path`, `requested`, `added`,
`skipped`, and `truncated` fields are the complete HTTP and CLI contract.

`IntegrityCheckerResolverInterface` owns checker selection and the canonical live-check dispatch.
Replace or decorate it to change priority, tenant policy, observability, or fallback behavior across
integrity scans, heal previews, and rollback execution. Tagged `IntegrityCheckerInterface` services
remain the additive seam for leaf binary checkers; replacing the resolver does not disable that tag
mechanism unless the replacement intentionally chooses a different policy.
`HealthCheckerInterface` owns aggregation and overall-status policy for both `GET /health` and
`asset-pilot:health`; tagged `HealthCheckInterface` probes remain the additive seam. Replace or
decorate the checker when tenants need different rollout policy, filtering, or observability.

`NotificationDispatcherInterface` is the producer-side fan-out seam; tagged `NotifierInterface`
services remain additive transports. Each transport receives one immutable `Notification` with an
extensible kind, typed severity, presentation text, and safe scalar context. Decorators can enrich,
filter, or observe notifications without parsing prose or replacing transport discovery.


`DuplicateMergeServiceInterface::preview()` is the read-only capability. `merge()` is the apply
capability and requires the exact fingerprint map returned by `fingerprintMap()`/`planTargets()`;
replacements must reject stale state after acquiring their mutation locks and must not silently
rebuild an omitted reviewed plan.

`ElementAuthorizationInterface` is the actor-aware authorization policy used consistently by HTTP,
CLI, listeners, and queue workers. A replacement must preserve explicit system actors and must never
fall back to Pimcore's permissive no-current-user CLI behavior for user-initiated work.

`ApplyPlanClaimStoreInterface` is the persistence seam for signed plan consumption. Its `claim()`
method must be atomic across every web and worker process: return `true` for the first claim ID and
`false` for every replay until the supplied expiry. The default DBAL store enforces this with the
primary key of `asset_pilot_apply_plan_claim` and removes expired rows in bounded batches. Re-point
the alias only to storage with the same cross-process guarantee.

The dependency projection is split into focused seams so consumers can replace storage without
replacing policy. `DependencyProjectionInterface` owns atomic dirty/refresh/remove operations,
`DependencyProjectionFreshnessInterface` owns generations and resumable cursor state,
`DependencyProjectionRebuilderInterface` performs bounded bootstrap batches, and
`DependencyUsageVerifierInterface` returns the policy verdict used by destructive operations. A
custom implementation must preserve revision fencing, durable cross-process freshness, positive
reference detection during dirty periods, and `unknown` for every state where safety cannot be
proven. It must never translate unavailable or stale data to `safe`.

Two related seams round this out. `ProjectionMarkerConnectionInterface` (default
`ProjectionMarkerConnection`, aliased and non-final) selects the database connection used to publish
the dirty marker, the dependency edge, and the deletion-fence read; its default routes to a dedicated
autocommit connection while a consumer-owned transaction is open, so a consumer can supply a different
connection policy. `AssetDeletionFenceInterface` (default `DbalAssetDeletionFence`, likewise aliased
and non-final) owns the pre-delete fence handshake. Both are curated out of the table above but are
replaceable in the same way; preserve the cross-connection visibility contract if you override either.

The content, integrity, duplicate, quarantine, normalization, storage, metadata, folder, drift, rules,
and metrics facades are application-level seams. Replace one to own that capability or decorate it to
add tenant policy, observability, or domain behavior while preserving the default implementation.
Controllers, commands, workers, strategies, rule actions, and maintenance tasks depend on these
interfaces, so one alias override is applied consistently across HTTP, CLI, and queue execution.

The operation-run, journal, recovery, delivery, and reviewed-operation aliases are advanced
infrastructure seams. Replacements must preserve actor scoping, atomic state transitions, lock
fencing, idempotency, and recovery semantics defined by their interfaces and tests. For most
consumer behavior, decorate the organizer, planner, dispatcher, search, ZIP, audit, or resolver
facades instead.

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

The same pattern replaces any row in the table above, including a custom `RuleEngine`,
`ConfidenceScorer`, or one focused audit capability.

## Decorate a core service

When you want to keep the default and only add to it, decorate. The original is injected as `.inner`:

```php
// src/Asset/LoggingAuditWriter.php
namespace App\Asset;

use Oronts\AssetPilotBundle\Audit\AuditWriterInterface;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: AuditWriterInterface::class)]
final class LoggingAuditWriter implements AuditWriterInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly AuditWriterInterface $inner,
        private readonly LoggerInterface $logger,
    ) {}

    public function log(MoveOperation $operation): void
    {
        $this->inner->log($operation);
        $this->logger->info('Asset operation recorded.', ['assetId' => $operation->assetId]);
    }
}
```

Decoration is transparent to the writer alias: every organization path gets the decorator, while
query, export, and retention consumers keep their independent aliases. The durable `in_progress`
operation lifecycle is managed separately by the operation journal.

## Swap a default move strategy

A rule's `strategy` option accepts only the three built-in values (`always`, `first_assignment`,
`callback`), defaulting to `always`; there is no way to register a fourth strategy name. To change move
behavior:

- For per-rule custom logic, use `strategy: callback` with a `callback` service implementing
  `CallbackDecisionInterface`, which needs no service overrides at all (see
  [Extending: Custom Strategy](extending.md#custom-strategy) and
  [Scenarios](scenarios.md#callback-strategy-with-custom-logic)).
- To change what a built-in strategy does globally, decorate or replace its service
  (`AlwaysMoveStrategy`, `FirstAssignmentStrategy`, or `CallbackStrategy`).

## Override configuration defaults

Most behavior is configuration, not code. Set these in `config/packages/` instead of overriding
services:

- `protection.lock_property`: the property name that locks an asset (default `asset_pilot_locked`).
- `protection.exclude_folders`: folder trees never touched by organization.
- `async.enabled`, `async.batch_size`: synchronous vs queued moves and bulk batch size.
- `audit.retention_days`: how long audit rows are kept.
- `locales`: the locales scanned for localized fields.
- `confidence.recently_uploaded_days`, `confidence.probably_unused_days`: the day cutoffs for the
  unused-asset confidence buckets (used by both scoring and the `?confidence=` filter).

See [Configuration](configuration.md) for the full tree.

To change the scoring *logic* itself (not just the day cutoffs), replace the owning service:

- The confidence classification algorithm lives in `ConfidenceScorer`. Replace
  `ConfidenceScorerInterface` to change how assets are scored beyond the configurable day windows.

## Override the Twig path-resolution behavior

`TemplatePathResolver` is the default `PathResolverInterface`, a single service (not a tagged chain).
To change how target paths are built, replace that alias with your own implementation, see
[Extending: Custom Path Resolver](extending.md#custom-path-resolver). To only add filters or
functions to the existing Twig environment, tag a Twig extension instead, see
[Extending: Add Twig Filters/Functions](extending.md#add-twig-filtersfunctions-to-path-templates).
To inject extra variables into templates without replacing the resolver, tag a context provider, see
[Extending: Add Path-Template Variables](extending.md#add-path-template-variables).

## Override the Studio UI

The dashboard ships as a Module Federation remote built from `assets/studio`. To customize it, fork
that source (or build your own remote that mounts under the Asset Pilot route) and build it as
described in [Studio UI](studio-ui.md#building-the-frontend). The REST backend it talks
to is documented in [REST API](rest-api.md) and is stable on its own.
