[← Documentation index](index.md) · [Project README](../README.md)

# Extending

Asset Pilot is built around interfaces. Swap out any component by implementing the interface and registering it as a service.

> The interface-based seams below (rule action, health check, integrity checker, notifier, duplicate
> merge strategy, rule provider, zip strategy, context provider) are auto-tagged container-wide, so
> implementing the interface is enough as long as your service has `autoconfigure: true` (the Symfony
> default). If you register the service with `autoconfigure: false`, add the seam's tag yourself (the
> tag name is listed with each seam). Strategies (keyed by a tag `alias`), filters, and the Twig /
> ExpressionLanguage providers are always tagged explicitly.

### Custom Filter

Restrict which assets a rule applies to. All registered filters run inside `CompositeFilter` using AND logic — the first rejection short-circuits evaluation.

```php
namespace App\AssetPilot\Filter;

use Oronts\AssetPilotBundle\Filter\AssetFilterInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class PublishedOnlyFilter implements AssetFilterInterface
{
    public function accept(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        if ($object instanceof \Pimcore\Model\DataObject\Concrete) {
            return $object->isPublished();
        }
        return true;
    }
}
```

```yaml
services:
    App\AssetPilot\Filter\PublishedOnlyFilter:
        tags:
            - { name: 'oronts_asset_pilot.filter' }
```

### Custom Strategy

Control when assets should be moved. Implement `ConflictStrategyInterface` (or provide a plain
invokable service), tag it with `oronts_asset_pilot.callback`, and reference it through the built-in
`callback` strategy: the rule sets `strategy: callback` and `callback: <your service id>`, and
`CallbackStrategy` resolves it from a service locator scoped to the tagged callbacks (not the full
container) and delegates the decision to your `resolve()`.

```php
namespace App\AssetPilot\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\ConflictStrategyInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class BusinessHoursStrategy implements ConflictStrategyInterface
{
    public function resolve(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        $hour = (int) date('H');
        return $hour >= 9 && $hour < 17;
    }

    public function supports(MoveStrategy $strategy): bool
    {
        // Reached via the callback strategy, not by direct resolver selection. Returning a built-in
        // value here would collide with the bundle's own CallbackStrategy, so return false.
        return false;
    }
}
```

```yaml
services:
    App\AssetPilot\Strategy\BusinessHoursStrategy:
        tags: ['oronts_asset_pilot.callback']   # exposed to CallbackStrategy's locator by service id
```

Reference it in a rule config:

```yaml
oronts_asset_pilot:
    rules:
        controlled_move:
            class: Product
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: callback
            callback: App\AssetPilot\Strategy\BusinessHoursStrategy
```

> The three built-in strategy names (`always`, `first_assignment`, `callback`) are the only values
> accepted by the rule `strategy` option. Custom logic plugs in through `callback`, as above; there
> is no separate custom strategy name to register.

### Add Twig Filters/Functions to Path Templates

To add filters or functions usable in `target_path` templates without replacing the resolver, tag a
Twig extension with `oronts_asset_pilot.twig_extension`:

```php
class AssetPilotTwigExtension extends \Twig\Extension\AbstractExtension
{
    public function getFilters(): array
    {
        return [new \Twig\TwigFilter('region_code', fn (string $v): string => substr($v, 0, 2))];
    }
}
```

```yaml
services:
    App\AssetPilot\Twig\AssetPilotTwigExtension:
        tags: ['oronts_asset_pilot.twig_extension']
```

Now `target_path: '/Regions/{{ object.getCountry()|region_code }}'` works.

### Add Functions to Rule Conditions

To add functions usable in rule `condition` expressions, tag a Symfony
`ExpressionFunctionProviderInterface` with `oronts_asset_pilot.expression_function_provider`:

```php
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

class AssetPilotExpressionProvider implements ExpressionFunctionProviderInterface
{
    public function getFunctions(): array
    {
        return [
            new ExpressionFunction(
                'in_business_hours',
                static fn (): string => '((int) date("H") >= 9 && (int) date("H") < 17)', // compiler
                static fn (array $vars): bool => (int) date('H') >= 9 && (int) date('H') < 17, // evaluator
            ),
        ];
    }
}
```

```yaml
services:
    App\AssetPilot\Expression\AssetPilotExpressionProvider:
        tags: ['oronts_asset_pilot.expression_function_provider']
```

Now `condition: 'in_business_hours() and is_image(asset)'` works.

### Add Path-Template Variables

The core template context is `object`, `asset`, `locale`, `date`, `className`. To expose your own
domain variables (e.g. `productCode`, `region`) to `target_path` templates, implement
`ContextProviderInterface` (auto-tagged via `oronts_asset_pilot.context_provider`):

```php
use Oronts\AssetPilotBundle\PathResolver\ContextProviderInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class DomainContextProvider implements ContextProviderInterface
{
    public function getContext(AbstractObject $object, Asset $asset, ?string $locale): array
    {
        return [
            'productCode' => method_exists($object, 'getProductCode') ? ($object->getProductCode() ?? 'unknown') : 'unknown',
        ];
    }
}
```

Now `target_path: '/Products/{{ productCode|safe_key }}'` works. (Consumer-specific fields live in a
provider, not in the generic bundle.)

> **Breaking change:** earlier versions pre-resolved several consumer-specific domain variables into the
> context automatically. They are no longer built in: a template that used such a variable must now
> register a context provider as above, or call the object getter directly
> (`{{ object.getProductCode()|default('unknown') }}`). Core keys (`object`, `asset`, `date`, `locale`,
> `className`) always win and cannot be overridden by a provider.

> Use names that do not clash with the built-ins. The bundle's own Twig filters/functions
> (`safe_key`, `pluck`, `coalesce`, ...) and condition functions (`is_image`, `asset_type`, ...) are
> registered first; reuse a built-in name and a consumer expression function will override it, while a
> Twig filter of the same name is shadowed by the built-in.

### Custom Path Resolver

Replace the Twig-based path resolution entirely. Implement `PathResolverInterface` and override the service alias.

```php
namespace App\AssetPilot\PathResolver;

use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\PathResolver\PathResolverInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class DatabasePathResolver implements PathResolverInterface
{
    public function __construct(private readonly \Doctrine\DBAL\Connection $db) {}

    public function resolve(
        AbstractObject $object,
        Asset $asset,
        Rule $rule,
        ?string $locale = null,
    ): string {
        $path = $this->db->fetchOne(
            'SELECT target_path FROM asset_path_mappings WHERE class_name = ? AND rule_name = ?',
            [$object->getClassName(), $rule->name]
        );
        return $path ?: '/Fallback/' . $object->getKey();
    }
}
```

```yaml
services:
    Oronts\AssetPilotBundle\PathResolver\PathResolverInterface:
        alias: App\AssetPilot\PathResolver\DatabasePathResolver
```

### Custom Condition Evaluator

Replace the ExpressionLanguage evaluator with your own logic. Implement `ConditionEvaluatorInterface` and override the service alias.

```php
namespace App\AssetPilot\Condition;

use Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class WorkflowConditionEvaluator implements ConditionEvaluatorInterface
{
    public function evaluate(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        // The live pipeline must never throw from a condition; swallow and treat errors as "no match".
        try {
            return $this->evaluateStrict($object, $asset, $rule, $locale);
        } catch (\Throwable) {
            return false;
        }
    }

    public function evaluateStrict(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        if ($rule->condition === null) {
            return true;
        }

        // Only proceed if the object's workflow state matches the condition
        return $object->getProperty('workflow_state') === $rule->condition;
    }
}
```

```yaml
services:
    Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface:
        alias: App\AssetPilot\Condition\WorkflowConditionEvaluator
```

### Custom Naming Strategy

Control how filenames are generated and collisions are resolved. Implement `NamingStrategyInterface` and override the service alias.

```php
namespace App\AssetPilot\Naming;

use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Pimcore\Model\Asset;

class HashNamingStrategy implements NamingStrategyInterface
{
    public function generateName(Asset $asset, string $targetPath): string
    {
        $ext = pathinfo($asset->getFilename(), PATHINFO_EXTENSION);
        $hash = substr(md5($asset->getFilename() . time()), 0, 8);
        return $hash . '.' . $ext;
    }
}
```

```yaml
services:
    Oronts\AssetPilotBundle\Naming\NamingStrategyInterface:
        alias: App\AssetPilot\Naming\HashNamingStrategy
```

### Programmatic Rules

Contribute rules from code instead of (or alongside) YAML, for example to build rules from another
config source. Implement `RuleProviderInterface`; services implementing it are auto-tagged
`oronts_asset_pilot.rule_provider`, and the `RuleEngine` merges their rules with the configured ones
and re-sorts by priority. `getRules()` may yield `Rule` objects or config arrays.

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Engine\RuleProviderInterface;
use Oronts\AssetPilotBundle\Model\Rule;

class TenantRuleProvider implements RuleProviderInterface
{
    public function getRules(): iterable
    {
        yield Rule::fromConfig('tenant_images', [
            'class' => 'Product',
            'fields' => ['images'],
            'target_path' => '/Tenants/{{ object.getTenant() }}/Images',
            'priority' => 50,
        ]);
    }
}
```

No service config is needed beyond autowiring; the interface tag is applied automatically.

### Multi-tenant scoping

The bundle stays tenant-agnostic: "tenant" is a consumer concept, so you wire it through the
existing hooks rather than configuring it in the bundle. Three seams compose into full
multi-tenancy, none of which require changing the bundle:

1. **Route per tenant** — a [path-template variable](#add-path-template-variables) exposes the
   tenant to `target_path`, so assets land under a per-tenant folder:

   ```php
   class TenantContextProvider implements ContextProviderInterface
   {
       public function getContext(AbstractObject $object, Asset $asset, ?string $locale = null): array
       {
           // Derive the tenant however your domain does (object field, folder, the acting user, ...).
           return ['tenant' => $object instanceof Concrete ? ($object->get('tenant') ?? 'shared') : 'shared'];
       }
   }
   ```

   ```yaml
   target_path: '/Assets/{{ tenant }}/{{ locale|default("shared") }}/{{ object.getKey()|safe_key }}'
   ```

2. **Match per tenant** — a rule `condition` reads the tenant straight off the object (conditions get
   `object`, `asset`, `rule`, `locale`); combine it with the `locales` key for language:

   ```yaml
   acme_de_images:
       class: Product
       fields: [images]
       condition: 'object.getTenant() == "acme"'
       locales: [de]
       target_path: '/Assets/acme/de/{{ object.getKey()|safe_key }}'
   ```

   For richer logic, register a [condition function](#add-functions-to-rule-conditions) such as
   `tenant_of(object)`.

3. **Generate rules per tenant** — when tenants are dynamic, emit one rule set per tenant from a
   [rule provider](#programmatic-rules) instead of hand-writing YAML.

Because every tenant hook is a tagged consumer service, the generic bundle carries no
tenant-specific logic, and a single-tenant install simply omits the provider.

### Add a Health Check

Contribute your own production-readiness probe to `asset-pilot:health` and `GET /health`. Implement
`HealthCheckInterface`; services implementing it are auto-tagged `oronts_asset_pilot.health_check`
and the `HealthChecker` runs each one (isolating a throwing check as a Critical result) and rolls
them up to the worst overall status.

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;

class ConverterBinaryHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'converter_binary';
    }

    public function run(): HealthCheckResult
    {
        if (is_executable('/usr/bin/convert')) {
            return new HealthCheckResult($this->name(), HealthStatus::Ok, 'ImageMagick is available.');
        }

        return new HealthCheckResult($this->name(), HealthStatus::Warning, 'ImageMagick (convert) was not found.');
    }
}
```

No service config is needed beyond autowiring; the interface tag is applied automatically. Keep the
public message free of secrets or internals (it is returned by the REST endpoint).

### Add an Integrity Checker

`asset-pilot:check-integrity` and `GET /integrity` detect assets whose binary no longer renders.
Detection runs the tagged checkers through `CompositeIntegrityChecker`, which picks the
highest-`priority()` checker whose `supports()` matches (the same composite-resolver pattern used by
filters and strategies). Implement `IntegrityCheckerInterface`; the service is auto-tagged
`oronts_asset_pilot.integrity_checker`. Built-ins: `StreamExistsChecker` (priority 0, universal),
`ImageIntegrityChecker` and `DocumentIntegrityChecker` (priority 20, Imagick-backed).

Return `IntegrityStatus::Unverifiable` (not `Broken`) when your tool is unavailable, so a missing
binary never causes a checker outage to be read as a broken asset. `check()` inspects the live
asset; `checkBinary()` inspects a candidate binary in memory (used by a future version-rollback heal,
and the natural place to share the verdict logic). Extending `AbstractBinaryIntegrityChecker` gives
you the stream-to-string bridge so you only implement `checkBinary()` plus `supports()`/`priority()`.

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\Check\AbstractBinaryIntegrityChecker;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

class SvgIntegrityChecker extends AbstractBinaryIntegrityChecker
{
    public function priority(): int
    {
        return 30;
    }

    public function supports(Asset $asset): bool
    {
        return str_ends_with(strtolower((string) $asset->getFilename()), '.svg');
    }

    protected function name(): string
    {
        return 'svg';
    }

    public function checkBinary(string $binary, string $extension): IntegrityResult
    {
        $xml = @simplexml_load_string($binary);
        if ($xml === false) {
            return new IntegrityResult(IntegrityStatus::Broken, $this->name(), 'SVG is not well-formed XML.');
        }

        return new IntegrityResult(IntegrityStatus::Renderable, $this->name(), null);
    }
}
```

No service config is needed beyond autowiring; the interface tag is applied automatically.

### Add a Notifier

When a bulk run's failure rate crosses `notifications.failure_rate_threshold`, the bundle dispatches
an alert to every tagged notifier. The built-in `PimcoreNotificationNotifier` sends a Pimcore in-app
("bell") notification to the configured user/group. Add email, Slack, or a webhook by implementing
`NotifierInterface`; it is auto-tagged `oronts_asset_pilot.notifier` and receives every alert.

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Notification\NotifierInterface;

class SlackNotifier implements NotifierInterface
{
    public function notify(string $title, string $message): void
    {
        // POST to your Slack webhook, send mail, etc.
    }
}
```

A notifier that throws is isolated and logged by the dispatcher, so a failing transport never blocks
the others or the operation that triggered the alert.

### Rule Actions (do more than move)

A rule can run post-move actions on the organized asset via its `actions` config. Each entry has a
`type` resolved to a tagged `oronts_asset_pilot.rule_action` service, plus that action's own keys.
Actions run only after a successful move, never in dry-run; a failing action is logged and isolated
(it never undoes the move or aborts the others).

The built-in `set_property` action sets an asset property from a static `value` or, for
object-derived metadata, from the owning object via a `from` getter:

```yaml
oronts_asset_pilot:
    rules:
        product_images:
            class: Product
            target_path: '/Products/{{ object.getKey()|safe_key }}'
            actions:
                - { type: set_property, name: cdn_ready, property_type: bool, value: true }
                - { type: set_property, name: product_code, from: productCode }   # $object->getProductCode()
```

Add your own action (e.g. assign a tag, derive any metadata, call an external system) by
implementing `RuleActionInterface`; it is auto-tagged and selected by `getType()`:

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Action\RuleActionInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class AssignReviewTagAction implements RuleActionInterface
{
    public function getType(): string
    {
        return 'assign_review_tag';
    }

    public function apply(Asset $asset, AbstractObject $object, array $config): void
    {
        // $config carries this action's keys; $object is the owning DataObject for derived values.
        // ... assign a tag / write metadata / notify ...
    }
}
```

Then reference it: `actions: [{ type: assign_review_tag }]`. An action that saves the asset must do
so loop-safely (see [Architecture](architecture.md)); the built-in `set_property` writes the
property directly, so it never re-enters the pipeline.

### Duplicate Merge Strategies

A duplicate merge picks a canonical asset, repoints every copy's references onto it, then hands each
copy to a *merge strategy* that decides its fate. Three are built in, selected by name via the
`duplicates.merge_strategy` config key (default `quarantine`) or the `strategy` body field on
`POST /duplicates/merge`:

| Name | Disposition |
|------|-------------|
| `quarantine` | Repoint, then soft-delete a fully-repointed copy to the quarantine folder (reversible via restore). Default. |
| `delete` | Repoint, then hard-delete a fully-repointed copy (re-verifies no reverse dependency + the workspace `delete` ACL first). |
| `isolate` | Never repoints; only quarantines copies that are already unreferenced (lowest risk). |

Add your own disposition by implementing `DuplicateMergeStrategyInterface`; it is auto-tagged
`oronts_asset_pilot.duplicate_merge_strategy` and selected by its `name()`:

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;

class TagForReviewStrategy implements DuplicateMergeStrategyInterface
{
    public function name(): string
    {
        return 'tag_for_review';
    }

    public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition
    {
        // A strategy MUST NOT destroy a copy whose references were not fully repointed.
        if (!$report->fullyRepointed) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, 'references remain');
        }

        // ... flag the copy for a human instead of deleting it ...
        return new CopyDisposition($copyId, DispositionOutcome::Skipped, 'tagged for review, left in place');
    }
}
```

No service config is needed beyond autowiring. Then select it: `duplicates: { merge_strategy: tag_for_review }`.

> Strategies are selected by **name**, not by asset type (there is no `supports()` filter): a strategy
> that should only handle certain types must check inside `disposeCopy()`. The v1 repointer rewrites
> top-level asset relations (image / many-to-one / many-to-many) and hard-coded asset paths/ids in
> WYSIWYG fields; references inside documents, nested bricks/blocks/fieldcollections or advanced/metadata
> relations are reported as blocked and that copy is left untouched (never silently merged).

### Events

Subscribe to asset move events for custom logic:

```php
namespace App\EventListener;

use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: AssetPilotEvents::PRE_MOVE)]
class AssetMoveListener
{
    public function __invoke(AssetMoveEvent $event): void
    {
        // Cancel moves to restricted paths
        if (str_starts_with($event->targetPath, '/Protected/')) {
            $event->cancel();
            return;
        }

        // Access event data: $event->asset, $event->object, $event->rule, $event->triggerType.
        // On POST_MOVE/MOVE_FAILED, $event->operation holds the MoveOperation and, on failure,
        // $event->throwable holds the cause. $event->isDryRun() is true during a preview.
    }
}
```

Move events (`AssetMoveEvent`):

| Event | Constant | Description |
|-------|----------|-------------|
| `oronts_asset_pilot.pre_move` | `AssetPilotEvents::PRE_MOVE` | Before move, cancellable (also fired in dry-run; `isDryRun()` is true) |
| `oronts_asset_pilot.post_move` | `AssetPilotEvents::POST_MOVE` | After a successful move; carries the `MoveOperation` |
| `oronts_asset_pilot.move_failed` | `AssetPilotEvents::MOVE_FAILED` | After a failed move; carries the `MoveOperation` and `throwable` |

Bulk events (`BulkOrganizeEvent`, carrying `objectIds`/`triggerType`/`results`):

| Event | Constant | Description |
|-------|----------|-------------|
| `oronts_asset_pilot.bulk_started` | `AssetPilotEvents::BULK_STARTED` | Before a bulk organize run |
| `oronts_asset_pilot.bulk_completed` | `AssetPilotEvents::BULK_COMPLETED` | After a bulk organize run; `results` populated |

Mutation events (`AssetMutationEvent`, carrying `assetIds`/`mutation`/`context`) — for hooking every
asset change outside the move pipeline (CDN purge, search reindex, DAM sync):

| Event | Constant | Description |
|-------|----------|-------------|
| `oronts_asset_pilot.asset_locked` | `AssetPilotEvents::ASSET_LOCKED` | An asset was locked |
| `oronts_asset_pilot.asset_unlocked` | `AssetPilotEvents::ASSET_UNLOCKED` | An asset was unlocked |
| `oronts_asset_pilot.asset_property_set` | `AssetPilotEvents::ASSET_PROPERTY_SET` | A property was bulk-set |
| `oronts_asset_pilot.assets_tagged` | `AssetPilotEvents::ASSETS_TAGGED` | Assets were bulk-tagged |
| `oronts_asset_pilot.unused_deleted` | `AssetPilotEvents::UNUSED_DELETED` | Unused assets were deleted |
| `oronts_asset_pilot.unused_moved` | `AssetPilotEvents::UNUSED_MOVED` | Unused assets were moved |
| `oronts_asset_pilot.reverted` | `AssetPilotEvents::REVERTED` | A move was reverted |
| `oronts_asset_pilot.quarantined` | `AssetPilotEvents::QUARANTINED` | Unused assets were quarantined (soft-deleted) |
| `oronts_asset_pilot.restored` | `AssetPilotEvents::RESTORED` | An asset was restored from quarantine |

Integrity heal events (`AssetHealEvent`, carrying `asset`/`targetVersion`/`outcome`) — for vetoing or
observing a version-rollback self-heal:

| Event | Constant | Description |
|-------|----------|-------------|
| `oronts_asset_pilot.integrity_pre_heal` | `AssetPilotEvents::INTEGRITY_PRE_HEAL` | Before a heal; cancellable via `cancel()` (veto restoring a broken asset to an older version) |
| `oronts_asset_pilot.integrity_post_heal` | `AssetPilotEvents::INTEGRITY_POST_HEAL` | After a heal attempt; `outcome` holds the `HealOutcome` |

## Asset field-type coverage

`AssetFieldExtractor` discovers the assets to organize across image, video, document, archive,
hotspotimage and imageGallery fields; every relation type (manyToOne / manyToMany / object and the
advanced relations, unwrapped from `ElementMetadata`); and assets nested in **object bricks**, **field
collections**, **localized fields** and **block** fields (reported under a qualified field name such
as `myBrick.image`). Unused-asset detection additionally relies on Pimcore's dependency table, which
records relation references regardless of where they are nested.

## Known Limitations

- **Classificationstore-held assets.** Assets referenced through a classification-store key are not
  picked up by the organizer (they are uncommon; the dependency table still records them, so unused
  detection stays correct). Add a `context_provider` or a custom extractor if you store assets there.
- **Content-reference scan field types.** The opt-in delete/move guard (`content_scan`) scans only the
  top-level `wysiwyg`, `textarea` and `input` fields of the configured classes for a hard-coded asset
  path. References inside nested bricks/blocks/fieldcollections, or in a custom field type that stores a
  path, are not scanned — treat it as a heuristic safety net, not a guarantee.
- **Merge strategy selection.** Merge strategies are chosen by `name()`, not by asset type (there is no
  `supports()` filter); type-specific behaviour must live inside `disposeCopy()`.
