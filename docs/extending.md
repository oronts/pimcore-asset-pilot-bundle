[← Documentation index](index.md) · [Project README](../README.md)

# Extending

Asset Pilot exposes supported interfaces, tags, and events for consumer customization. Add behavior through the seams below; replace or decorate the documented service aliases in [Overriding](overriding.md). Transaction, lock, crypto, and recovery internals are intentionally not inheritance APIs, with one exception: the projection marker connection policy (`ProjectionMarkerConnectionInterface`, default `ProjectionMarkerConnection`) is a supported, non-final seam for consumers that run asset-referencing saves inside their own database transaction (see [Overriding](overriding.md)).

> The interface-based seams below (callback decision, rule action, health check, integrity checker,
> notifier, duplicate merge strategy, rule provider, zip strategy, context provider, and durable
> operation observer) are auto-tagged
> container-wide, so
> implementing the interface is enough as long as your service has `autoconfigure: true` (the Symfony
> default). If you register the service with `autoconfigure: false`, add the seam's tag yourself (the
> tag name is listed with each seam). Strategies (keyed by a tag `alias`), filters, and the Twig /
> ExpressionLanguage providers are always tagged explicitly.

### Custom Filter

Restrict which assets a rule applies to. All registered filters run inside `CompositeFilter` using AND logic; the first rejection short-circuits evaluation.

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

Control when assets should be moved. Implement `CallbackDecisionInterface` (auto-tagged), or provide a plain
invokable service tagged `oronts_asset_pilot.callback`, and reference it through the built-in
`callback` strategy: the rule sets `strategy: callback` and `callback: <your service id>`, and
`CallbackStrategy` resolves it from a service locator scoped to the tagged callbacks (not the full
container) and delegates the decision to `decide()`.
Plain callable services receive the same four arguments: `Asset`, `AbstractObject`, `Rule`, and
`bool $dryRun`.

```php
namespace App\AssetPilot\Strategy;

use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\CallbackDecisionInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class BusinessHoursDecision implements CallbackDecisionInterface
{
    public function decide(Asset $asset, AbstractObject $object, Rule $rule, bool $dryRun): bool
    {
        $hour = (int) date('H');
        return $hour >= 9 && $hour < 17;
    }
}
```

```yaml
services:
    App\AssetPilot\Strategy\BusinessHoursDecision: ~ # auto-tagged and exposed by service id
```

Reference it in a rule config:

```yaml
oronts_asset_pilot:
    rules:
        controlled_move:
            class: Product
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: callback
            callback: App\AssetPilot\Strategy\BusinessHoursDecision
```

> The three built-in strategy names (`always`, `first_assignment`, `callback`) are the only values
> accepted by the rule `strategy` option. Custom logic plugs in through `callback`, as above; there
> is no separate custom strategy name to register.

`decide()` runs while building previews and again before apply. It must be query-only: do not save
Pimcore elements, dispatch external work, or write to another system. `$dryRun` is `true` while
building a preview and `false` immediately before the live move, so integrations can suppress
live-only diagnostics during a preview. `PRE_MOVE` listeners follow the same rule when
`AssetMoveEvent::isDryRun()` is true.

Drift evaluation never runs the `callback` strategy: `decide()` is invoked only for a preview or a
live move, never during a drift audit. Drift evaluates a strategy only when the rule resolves to a
directly registered `ConflictStrategyInterface` service that also implements
`SideEffectFreeConflictStrategyInterface` (as the built-in `always` and `first_assignment` strategies
do); its read-only `resolve()` then runs with `$dryRun = true` and the audit reports the known
rejection reason. Every other strategy, `callback` included, is reported as requiring an apply-time
check, because it is evaluated only when a move is actually requested.

### Add Twig Filters/Functions to Path Templates

To add filters or functions usable in `target_path` templates without replacing the resolver, tag a
Twig extension with `oronts_asset_pilot.twig_extension`:

```php
declare(strict_types=1);

namespace App\AssetPilot\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AssetPilotTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('region_code', fn (string $v): string => substr($v, 0, 2))];
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
declare(strict_types=1);

namespace App\AssetPilot\Expression;

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
declare(strict_types=1);

namespace App\AssetPilot\Context;

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

    public function validateTemplate(string $template): void
    {
        if ($template === '' || !str_starts_with($template, '/')) {
            throw new \InvalidArgumentException('Database resolver paths must be absolute.');
        }
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

    public function validateSyntax(string $expression): void
    {
        if (trim($expression) === '') {
            throw new \InvalidArgumentException('Workflow conditions cannot be empty.');
        }
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
        // Must be deterministic: reviewed preview and apply both recompute this, so time()/random would break the plan.
        $ext = pathinfo($asset->getFilename(), PATHINFO_EXTENSION);
        $hash = substr(md5($asset->getId() . ':' . $asset->getFilename() . ':' . $targetPath), 0, 8);
        return $ext !== '' ? $hash . '.' . $ext : $hash;
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

1. **Route per tenant**: a [path-template variable](#add-path-template-variables) exposes the
   tenant to `target_path`, so assets land under a per-tenant folder:

   ```php
   class TenantContextProvider implements ContextProviderInterface
   {
       public function getContext(AbstractObject $object, Asset $asset, ?string $locale): array
       {
           // Derive the tenant however your domain does (object field, folder, the acting user, ...).
           return ['tenant' => $object instanceof Concrete ? ($object->get('tenant') ?? 'shared') : 'shared'];
       }
   }
   ```

   ```yaml
   target_path: '/Assets/{{ tenant }}/{{ locale|default("shared") }}/{{ object.getKey()|safe_key }}'
   ```

2. **Match per tenant**: a rule `condition` reads the tenant straight off the object (conditions get
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

3. **Generate rules per tenant**: when tenants are dynamic, emit one rule set per tenant from a
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

To change the selection policy itself rather than add a checker, replace or decorate the
`IntegrityCheckerResolverInterface` alias; all integrity scans and heal paths consume that contract.

Return `IntegrityStatus::Unverifiable` (not `Broken`) when your tool is unavailable, so a missing
binary never causes a checker outage to be read as a broken asset. `check()` inspects the live
asset; `checkBinary()` inspects a candidate binary in memory. `VersionRollbackHealer` uses it to find
the newest renderable version before previewing or applying a rollback. Extending
`AbstractBinaryIntegrityChecker` gives you the stream-to-string bridge so you implement
`checkBinary()` plus `supports()`/`priority()`/`name()`.

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
notification to the configured users and groups. Add email, Slack, or a webhook by implementing
`NotifierInterface`; it is auto-tagged `oronts_asset_pilot.notifier` and receives every alert as one
immutable `Notification` value.

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Notification\Notification;
use Oronts\AssetPilotBundle\Notification\NotifierInterface;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

class SlackNotifier implements NotifierInterface
{
    public function __construct(private readonly ChatterInterface $chatter) {}

    public function notify(Notification $notification): void
    {
        $this->chatter->send(new ChatMessage(sprintf('%s: %s', $notification->severity->name, $notification->kind)));
    }
}
```

`kind` is a stable, extensible routing identifier; `severity` is a typed enum; and `context` contains
safe scalar routing data, so transports never need to parse the title or message. Built-in producers do
not put paths, exception messages, actor data, tokens, or credentials in context. Custom producers must
preserve that boundary. A notifier that throws is isolated and logged by the dispatcher, so a failing
transport never blocks the operation or the other notifiers.

### Rule Actions (do more than move)

A rule can run post-move actions on the organized asset via its `actions` config. Each entry has a
`type` resolved to a tagged `oronts_asset_pilot.rule_action` service, plus that action's own keys.
Action inputs are prepared before the move and persisted with the operation. Delivery starts only
after the journal records a committed move, restores the initiating actor, rechecks publish access,
and holds the shared asset lock. Delivery is at least once: implementations must make
`applyPrepared()` idempotent. A failing action is retried with bounded backoff and never undoes the
move or blocks other actions. Exhausted delivery changes an otherwise committed audit row to
`completed_with_observer_error`; it never rewrites a failed operation, and dead-delivery health
still reports the observer failure.

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

For `property_type: bool`, `value` accepts a native boolean, `1`/`0`, or the textual forms
`true`/`false`/`yes`/`no`/`on`/`off` (blank or unknown is rejected); this is the same canonical boolean
contract the REST `bulk-property` endpoint documents (see [REST API](rest-api.md)).

The built-in `convert_format` action re-encodes an image asset to a target `format` (`png`, `jpeg`, `gif`,
or `webp`) after it is organized, through a pluggable converter seam. A GD-backed converter ships in the
box; add Imagick, vips, or external-binary encoders by implementing `AssetConverterInterface` (see
[Overriding](overriding.md)). It is best-effort by contract: a non-image asset, an asset already in the
target format, a missing encoder, or a target-filename collision all skip without failing the organize.

```yaml
            actions:
                - { type: convert_format, format: webp, quality: 82 }   # quality (1-100) is optional
```

Add your own action (e.g. assign a tag, derive any metadata, call an external system) by
implementing `RuleActionInterface`; it is auto-tagged and selected by `getType()`:

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Action\RuleActionInterface;
use Oronts\AssetPilotBundle\Action\RuleActionDeliveryContextInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\Tag;

class AssignReviewTagAction implements RuleActionInterface
{
    public function getType(): string
    {
        return 'assign_review_tag';
    }

    public function prepare(Asset $asset, AbstractObject $object, array $config): array
    {
        // Return serializable values only; the Tag element is resolved at delivery time.
        return ['tag' => (string) ($config['tag'] ?? 'review')];
    }

    public function applyPrepared(Asset $asset, array $payload, RuleActionDeliveryContextInterface $delivery): void
    {
        $delivery->heartbeat();

        $tag = Tag::getByPath('/' . $payload['tag']);
        if (!$tag instanceof Tag) {
            return;
        }

        // Naturally idempotent: the (tag, element) assignment is keyed, so an at-least-once retry
        // re-adds the same pair without duplicating it. For an external system instead, send
        // $delivery->deliveryId() as the idempotency key so the retry is deduplicated there.
        Tag::addTagToElement('asset', (int) $asset->getId(), $tag);
    }
}
```

Then reference it: `actions: [{ type: assign_review_tag }]`. Delivery is at least once. Use `deliveryId()` as the stable idempotency key across retries; `attempt()`
is diagnostic only. Long actions must call `heartbeat()` more frequently than
`operation_journal.lease_seconds` and immediately before irreversible work. If heartbeat throws, the
lease was lost and the action must stop. Delivery already owns the bundle's processing marker and
shared asset lock, and each `heartbeat()` renews that asset lock together with the delivery lease, so a
long action keeps exclusive ownership for its whole run. The built-in `set_property` skips an
already-matching property before saving.

An action can also implement `RuleActionConfigValidatorInterface`. Its `validateConfig()` errors are
reported by `asset-pilot:validate-config`, so consumer action configuration can fail validation
before a post-move execution reaches production.

### Durable operation observers

Implement `DurableOperationObserverInterface` for integration work that must survive process or
broker failure. It is auto-tagged as `oronts_asset_pilot.operation_observer`. `prepare()` runs before
the asset mutation and must only return serializable `PreparedDelivery` values; it must not change
external state. `deliver()` receives the stored `DeliveryEnvelope` after the selected success or
failure outcome is committed. Delivery restores the initiating actor, is leased, retried, and at
least once, so the delivery key and handler must be idempotent.

The processor renews the claim immediately before and after `deliver()`. An observer whose work can
approach the configured lease must also call `$delivery->heartbeat()` periodically and immediately
before each irreversible unit of work. The heartbeat is fenced by the current claim token and throws
when the lease expired or another worker reclaimed it; the observer must then stop.

Implement `requiredAssetPermission()` as part of the delivery contract. Return a Pimcore asset
permission such as `publish` when delivery loads or changes the asset. The processor then reloads
the asset, rechecks that permission under the restored actor, and holds the shared asset lock while
calling `deliver()`. Return `null` only when delivery uses persisted envelope metadata and does not
load or mutate the asset; metadata-only delivery intentionally avoids the asset lookup and lock.

The built-in observers implement durable rule actions and dispatch
`oronts_asset_pilot.operation_succeeded` / `oronts_asset_pilot.operation_failed`. Use these durable
events for integration side effects; the richer synchronous move events remain suitable for
in-process vetoes and diagnostics.

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
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;

class TagForReviewStrategy implements DuplicateMergeStrategyInterface
{
    public function name(): string
    {
        return 'tag_for_review';
    }

    public function repointsReferences(): bool
    {
        return true;
    }

    public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
    {
        $copyId = $context->copyId();

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

`disposeCopy()` always receives a fenced `DuplicateMergeContextInterface` as its second argument. This
is the only contract; there is no context-free path in 2.0. A quick, in-process disposition can ignore
it. A long-running or externally-integrated one must use it to stay safe while it works:

- `heartbeat()` renews the operation-run item lease and every held asset/referrer lock together and
  throws `MergeLeaseLostException` the instant any is lost. Call it more often than
  `idempotency.lock_ttl` (default 60s) and immediately before each irreversible or external step; stop
  as soon as it throws.
- `save(callable $mutator)` performs a guarded save of the copy under the held lock (it heartbeats first,
  so a write can never land after ownership lapsed).
- `idempotencyKey()` (`duplicate-merge:<rootRunId>:<checksum>:<copyId>`) is stable across attempt, resume,
  and retry, so external side effects can dedupe. `operationId()`, `itemKey()`, `copyId()`,
  `canonicalId()`, `attempt()`, and `actor()` identify the work.

A strategy that can be interrupted after an external or destructive side effect must also implement
`ResumableDuplicateMergeStrategyInterface` and make `recoverDisposition()` recognize its committed state,
so a `MergeLeaseLostException` (which terminalizes the item at the `Disposing` phase) re-drives
`recoverDisposition()` on retry rather than repeating the side effect. Non-resumable strategies are
blocked during recovery rather than executed twice.

> Strategies are selected by **name**, not by asset type (there is no `supports()` filter): a strategy
> that should only handle certain types must check inside `disposeCopy()`. The bundled
> `RepointAndDeleteStrategy` rewrites
> top-level asset relations (image / many-to-one / many-to-many) and hard-coded asset paths/ids in
> WYSIWYG fields; references inside documents, nested bricks/blocks/fieldcollections or advanced/metadata
> relations are reported as blocked and that copy is left untouched (never silently merged).

### Add a ZIP Layout Strategy

The download-zip subsystem (CLI `asset-pilot:download-zip`, REST `POST /assets/download-zip`) decides
where each asset lands inside the archive via a `ZipEntryStrategyInterface`. Built-ins are `flat`
(no folders), `folder` (mirrors the asset tree), and `type` (grouped by asset type). Add your own by
implementing the interface; it is auto-tagged `oronts_asset_pilot.zip_strategy` and selected by
`getName()`:

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Zip\ZipEntryStrategyInterface;
use Pimcore\Model\Asset;

class InvoiceZipStrategy implements ZipEntryStrategyInterface
{
    public function getName(): string
    {
        return 'invoice';
    }

    public function entryPath(Asset $asset): string
    {
        // Return the asset's relative path inside the archive (a trailing filename, no leading slash).
        return 'invoices/' . $asset->getFilename();
    }
}
```

Select it as the default via `zip: { default_strategy: invoice }`, or per request with the CLI
`--strategy=invoice` option / the REST `strategy` body field. Returning a path that collides with
another entry is de-duplicated by the archive builder, so two assets that map to the same name still
both pack.

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

Mutation events (`AssetMutationEvent`, carrying `assetIds`/`mutation`/`context`) cover the supported
interactive asset changes outside the move pipeline (CDN purge, search reindex, DAM sync):

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
| `oronts_asset_pilot.filename_normalized` | `AssetPilotEvents::FILENAME_NORMALIZED` | An asset filename was normalized to a safe form |
| `oronts_asset_pilot.empty_folder_deleted` | `AssetPilotEvents::EMPTY_FOLDER_DELETED` | An empty asset folder was deleted |

> These notify listeners of a completed mutation and are best-effort: a listener that throws is isolated and
> logged (it never rolls back the mutation), and the event is not replayed if the process crashes after the
> mutation commits. Where the operation records durable run/audit history a consumer needing a guaranteed
> signal can reconcile from it; filename normalization and empty-folder deletion write no journal/audit
> record, so subscribe to their events (or read the PSR log) if you must not miss them.

Duplicate merge events (`DuplicateMergeEvent`) are emitted only after a copy's persisted merge
phase commits:

| Event | Constant | Description |
|-------|----------|-------------|
| `oronts_asset_pilot.duplicate_merge_committed` | `AssetPilotEvents::DUPLICATE_MERGE_COMMITTED` | A copy's reference repoint and disposition committed; carries run, checksum, canonical, strategy, disposition, repoint report, and item status |

Integrity heal events (`AssetHealEvent`, carrying `asset`/`targetVersion`/`outcome`), for vetoing or
observing a version-rollback self-heal:

| Event | Constant | Description |
|-------|----------|-------------|
| `oronts_asset_pilot.integrity_pre_heal` | `AssetPilotEvents::INTEGRITY_PRE_HEAL` | Before a heal; cancellable via `cancel()` (veto restoring a broken asset to an older version) |
| `oronts_asset_pilot.integrity_post_heal` | `AssetPilotEvents::INTEGRITY_POST_HEAL` | After a heal attempt; `outcome` holds the `HealOutcome` |

## Asset field-type coverage

`AssetFieldExtractor` discovers the assets to organize across the native Pimcore asset field types
(image, video with its poster, hotspotimage and imageGallery); asset-capable relation fields (manyToOne
/ manyToMany and the advanced relations, whenever the field definition allows assets via
`getAssetsAllowed()`, unwrapped from `ElementMetadata`); and assets nested in **object bricks**, **field
collections**, **localized fields** and **block** fields (reported under a qualified field name such as
`myBrick.image`). Document and archive assets are referenced through those asset-capable relation
fields, not through direct field types; object-only relations (for example `manyToManyObjectRelation`)
carry no assets. Unused-asset detection additionally relies on Pimcore's dependency table, which records
relation references regardless of where they are nested.

## Known Limitations

- **Classificationstore-held assets (dependency safety).** Pimcore does not record classification-store
  asset references as dependency-table edges (`Classificationstore` has no `resolveDependencies()`), so the
  bundle traverses them itself: `AssetFieldExtractor::classificationStoreAssetIds()` reads each
  classification-store field and `AssetDependencyTargetExtractor` merges those asset IDs into both the
  dependency projection and the pre-save deletion fence. This traversal fails closed. When a key cannot be
  resolved, a value has an unexpected shape, or a field read throws, the extraction is marked incomplete, the
  source is kept `dirty` (never certified clean), and the usage verdict for any not-positively-referenced
  asset is `Unknown`, so unused delete, quarantine purge, and duplicate hard-delete are blocked rather than
  allowed on a partial read. The tradeoff is availability, not safety: a classification-store field that stays
  unreadable keeps the projection dirty and blocks destructive cleanup globally until the data is corrected.
  The `content_scan` guard still matches only asset paths in text columns, not the numeric ID a
  classification-store field stores, so classification-store coverage comes from this traversal, not from
  `content_scan`. A `context_provider` supplies template variables only and does not feed dependency safety.
- **Content-reference scan storage formats.** The opt-in delete/move guard (`content_scan`)
  dynamically scans string, text, and JSON columns in Pimcore `properties`, `object_*`,
  `documents_*`, and `classificationstore_*` tables. Custom content stored outside those tables or
  in non-text columns is not covered. Element dependencies are resolved into the indexed projection
  at save time; incomplete bootstrap, dirty sources, or rebuild failures return `unknown` and block
  mutation.
- **Merge strategy selection.** Merge strategies are chosen by `name()`, not by asset type (there is no
  `supports()` filter); type-specific behaviour must live inside `disposeCopy()`.
- **Consumer-owned database transactions.** An asset-referencing element save may run inside your own
  outer transaction; the projection publishes its dirty marker, edge, and deletion-fence read on a
  dedicated autocommit connection (`ProjectionMarkerConnectionInterface`, see [Overriding](overriding.md)).
  Two limitations follow: (1) creating an asset and an element that references it in the *same*
  transaction is unsupported (the still-uncommitted asset is invisible to the sidecar connection); and
  (2) if you roll back an otherwise-successful save, the already-committed dependency edge remains as a
  phantom, so a later delete of that asset is conservatively blocked (fail-safe over-block) until the
  source is re-saved or the projection is rebuilt with `asset-pilot:rebuild-dependency-projection`. The
  asset delete path itself still cannot run inside an ambient transaction.
