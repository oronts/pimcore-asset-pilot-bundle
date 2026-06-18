[← Documentation index](index.md) · [Project README](../README.md)

# Extending

Asset Pilot is built around interfaces. Swap out any component by implementing the interface and registering it as a service.

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
domain variables (e.g. `sapId`, `categories`) to `target_path` templates, tag a
`ContextProviderInterface` with `oronts_asset_pilot.context_provider`:

```php
use Oronts\AssetPilotBundle\PathResolver\ContextProviderInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class CommerceContextProvider implements ContextProviderInterface
{
    public function getContext(AbstractObject $object, Asset $asset, ?string $locale): array
    {
        return [
            'sapId' => method_exists($object, 'getSapId') ? ($object->getSapId() ?? 'unknown') : 'unknown',
        ];
    }
}
```

```yaml
services:
    App\AssetPilot\Context\CommerceContextProvider:
        tags: ['oronts_asset_pilot.context_provider']
```

Now `target_path: '/Products/{{ sapId|safe_key }}'` works. (Consumer-specific fields like `sapId`
live in a provider, not in the generic bundle.)

> **Breaking change:** earlier versions pre-resolved `sapId`, `categories`, `category`, `salesOrgs`,
> and `salesOrg` into the context automatically. They are no longer built in. A template using
> `{{ sapId }}` (etc.) must now register a context provider as above, or call the object method
> directly (`{{ object.getSapId()|default('unknown') }}`). Core keys (`object`, `asset`, `date`,
> `locale`, `className`) always win and cannot be overridden by a provider.

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
    public function evaluate(AbstractObject $object, Asset $asset, Rule $rule): bool
    {
        // The live pipeline must never throw from a condition; swallow and treat errors as "no match".
        try {
            return $this->evaluateStrict($object, $asset, $rule);
        } catch (\Throwable) {
            return false;
        }
    }

    public function evaluateStrict(AbstractObject $object, Asset $asset, Rule $rule): bool
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
