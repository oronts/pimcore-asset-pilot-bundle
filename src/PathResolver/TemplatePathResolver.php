<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\PathResolver;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Service as AssetService;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-powered template path resolver.
 *
 * Supports full Twig syntax in target_path templates:
 *
 *   Method calls:     {{ object.getSapId() }}
 *   Property access:  {{ object.key }}, {{ object.id }}
 *   Array access:     {{ categories[0].sapId }}
 *   Null coalescing:  {{ object.getSapId()|default('unknown') }}
 *   Conditionals:     {% if categories|length > 0 %}{{ categories[0].key }}{% else %}uncategorized{% endif %}
 *   Joins:            {{ categories|pluck('sapId')|join('-') }}
 *   Custom filters:   {{ value|safe_key }}, {{ items|pluck('key') }}, {{ items|first_of('sapId') }}
 *   Custom functions: coalesce(a, b, c), prop(obj, 'method', ...args)
 *
 * Pre-resolved context variables:
 *   object       — the DataObject
 *   asset        — the Asset being organized
 *   locale       — locale code for localized fields (e.g. 'en', 'de') or null
 *   date         — DateTimeImmutable (use date.format('Y') etc.)
 *   sapId        — object.getSapId() ?? 'unknown'
 *   className    — object class name
 *   categories   — object.getCategories() ?? []
 *   category     — first category or null
 *   salesOrgs    — object.getSalesOrganizations() ?? []
 *   salesOrg     — first sales org or null
 *
 */
class TemplatePathResolver implements PathResolverInterface
{
    protected ?Environment $twig = null;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function resolve(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): string
    {
        $template = $rule->targetPath;
        $context = $this->buildContext($object, $asset, $locale);

        try {
            $resolved = $this->render($template, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: template rendering failed for rule "{rule}": {error}', [
                'rule' => $rule->name,
                'error' => $e->getMessage(),
                'template' => $rule->targetPath,
            ]);
            $resolved = '/Assets/' . ($object->getKey() ?? 'unknown');
        }

        // Remove empty segments caused by null/empty resolves
        $segments = array_filter(
            explode('/', $resolved),
            static fn (string $s): bool => $s !== '' && $s !== 'unknown',
        );

        // If we removed all segments, use fallback
        if (empty($segments)) {
            $segments = ['Assets', $object->getKey() ?? 'unknown'];
        }

        // Re-add 'unknown' segments that were intentionally placed
        $rawSegments = array_filter(explode('/', $resolved), static fn (string $s): bool => $s !== '');

        $sanitized = array_map(
            static fn (string $segment): string => AssetService::getValidKey($segment, 'asset'),
            $rawSegments,
        );

        // Remove consecutive duplicate "unknown" segments
        $deduped = [];
        foreach ($sanitized as $seg) {
            if ($seg === 'unknown' && !empty($deduped) && end($deduped) === 'unknown') {
                continue;
            }
            $deduped[] = $seg;
        }

        $path = '/' . implode('/', $deduped);

        $this->logger->debug('Asset Pilot: resolved "{template}" → "{path}" (rule: {rule})', [
            'template' => $rule->targetPath,
            'path' => $path,
            'rule' => $rule->name,
        ]);

        return $path;
    }

    protected function buildContext(AbstractObject $object, Asset $asset, ?string $locale = null): array
    {
        $now = new \DateTimeImmutable();

        $context = [
            'object' => $object,
            'asset' => $asset,
            'date' => $now,
            'locale' => $locale,
            'className' => $object instanceof Concrete ? $object->getClassName() : 'Folder',
            'sapId' => 'unknown',
            'categories' => [],
            'category' => null,
            'salesOrgs' => [],
            'salesOrg' => null,
        ];

        // Pre-resolve sapId
        if (method_exists($object, 'getSapId')) {
            $sapId = $object->getSapId();
            $context['sapId'] = $sapId !== null && $sapId !== '' ? (string) $sapId : 'unknown';
        }

        // Pre-resolve categories
        if (method_exists($object, 'getCategories')) {
            $cats = $object->getCategories();
            if (is_array($cats) && !empty($cats)) {
                $context['categories'] = $cats;
                $context['category'] = $cats[0];
            }
        }

        // Pre-resolve sales organizations
        if (method_exists($object, 'getSalesOrganizations')) {
            $orgs = $object->getSalesOrganizations();
            if (is_array($orgs) && !empty($orgs)) {
                $context['salesOrgs'] = $orgs;
                $context['salesOrg'] = $orgs[0];
            }
        }

        return $context;
    }

    /**
     * Parse-only check used by config validation. Uses the same configured Twig environment as
     * render(), so a template using the bundle's own filters/functions (safe_key, pluck, coalesce,
     * ...) is not falsely reported as a syntax error. Throws on invalid syntax.
     */
    public function validateTemplate(string $template): void
    {
        $twig = $this->getTwig();
        $twig->parse($twig->tokenize(new Source($template, 'path')));
    }

    protected function render(string $template, array $context): string
    {
        $twig = $this->getTwig();

        /** @var ArrayLoader $loader */
        $loader = $twig->getLoader();
        $loader->setTemplate('path', $template);

        return $twig->render('path', $context);
    }

    protected function getTwig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        $loader = new ArrayLoader();
        $this->twig = new Environment($loader, [
            'strict_variables' => false,
            'autoescape' => false,
            'cache' => false,
        ]);

        $this->registerFilters($this->twig);
        $this->registerFunctions($this->twig);

        return $this->twig;
    }

    protected function registerFilters(Environment $twig): void
    {
        // |safe_key — sanitize a value for use as a path segment
        $twig->addFilter(new TwigFilter('safe_key', static function (mixed $value): string {
            if ($value === null || $value === '' || $value === false) {
                return 'unknown';
            }
            $str = (string) $value;
            return preg_replace('/[^a-zA-Z0-9_\-.]/', '-', $str) ?: 'unknown';
        }));

        // |pluck('property') — extract a property/method from each item in an array
        // Usage: {{ categories|pluck('sapId') }} → ['SAP001', 'SAP002']
        // Usage: {{ categories|pluck('getSapId') }} → same, calls method
        $twig->addFilter(new TwigFilter('pluck', static function (mixed $items, string $property): array {
            if (!is_iterable($items)) {
                return [];
            }

            $values = [];
            foreach ($items as $item) {
                if (is_object($item)) {
                    // Try getter first
                    $getter = 'get' . ucfirst($property);
                    if (method_exists($item, $getter)) {
                        $val = $item->$getter();
                    } elseif (method_exists($item, $property)) {
                        $val = $item->$property();
                    } elseif (property_exists($item, $property)) {
                        $val = $item->$property;
                    } else {
                        $val = null;
                    }

                    if ($val !== null) {
                        $values[] = (string) $val;
                    }
                } elseif (is_array($item) && isset($item[$property])) {
                    $values[] = (string) $item[$property];
                }
            }

            return $values;
        }));

        // |first_of('property') — get property from first item, or 'unknown'
        // Usage: {{ categories|first_of('sapId') }} → 'SAP001'
        $twig->addFilter(new TwigFilter('first_of', static function (mixed $items, string $property, string $fallback = 'unknown'): string {
            if (!is_iterable($items)) {
                return $fallback;
            }

            foreach ($items as $item) {
                if (is_object($item)) {
                    $getter = 'get' . ucfirst($property);
                    if (method_exists($item, $getter)) {
                        $val = $item->$getter();
                        if ($val !== null && $val !== '') {
                            return (string) $val;
                        }
                    }
                    if (method_exists($item, $property)) {
                        $val = $item->$property();
                        if ($val !== null && $val !== '') {
                            return (string) $val;
                        }
                    }
                }
                break; // only check first
            }

            return $fallback;
        }));

        // |slug — URL-safe slug
        $twig->addFilter(new TwigFilter('slug', static function (mixed $value): string {
            if ($value === null || $value === '') {
                return 'unknown';
            }
            $str = (string) $value;
            $str = mb_strtolower($str);
            $str = preg_replace('/[^a-z0-9]+/', '-', $str) ?? $str;
            return trim($str, '-') ?: 'unknown';
        }));

        // |fallback('default') — like |default but also catches empty strings
        $twig->addFilter(new TwigFilter('fallback', static function (mixed $value, string $default = 'unknown'): string {
            if ($value === null || $value === '' || $value === false) {
                return $default;
            }
            return (string) $value;
        }));

        // |trim_path — remove leading/trailing slashes
        $twig->addFilter(new TwigFilter('trim_path', static function (mixed $value): string {
            return trim((string) ($value ?? ''), '/');
        }));
    }

    protected function registerFunctions(Environment $twig): void
    {
        // coalesce(val1, val2, ...) — first non-null, non-empty value
        $twig->addFunction(new TwigFunction('coalesce', static function (mixed ...$values): string {
            foreach ($values as $value) {
                if ($value !== null && $value !== '' && $value !== false) {
                    return (string) $value;
                }
            }
            return 'unknown';
        }, ['is_variadic' => true]));

        // prop(obj, 'method', ...args) — safely call a method on any object
        $twig->addFunction(new TwigFunction('prop', static function (mixed $obj, string $method, mixed ...$args): mixed {
            if ($obj === null || !is_object($obj) || !method_exists($obj, $method)) {
                return null;
            }
            return $obj->$method(...$args);
        }, ['is_variadic' => true]));

        // rel(object, 'relation', index) — safely access a relation item
        $twig->addFunction(new TwigFunction('rel', static function (mixed $object, string $relation, int $index = 0): mixed {
            if ($object === null || !is_object($object)) {
                return null;
            }

            $getter = 'get' . ucfirst($relation);
            if (!method_exists($object, $getter)) {
                return null;
            }

            $items = $object->$getter();
            if (!is_array($items)) {
                return is_object($items) ? $items : null;
            }

            return $items[$index] ?? null;
        }));

        // has_relation(object, 'relation') — check if relation has items
        $twig->addFunction(new TwigFunction('has_relation', static function (mixed $object, string $relation): bool {
            if ($object === null || !is_object($object)) {
                return false;
            }

            $getter = 'get' . ucfirst($relation);
            if (!method_exists($object, $getter)) {
                return false;
            }

            $items = $object->$getter();
            if (is_array($items)) {
                return !empty($items);
            }

            return $items !== null;
        }));
    }
}
