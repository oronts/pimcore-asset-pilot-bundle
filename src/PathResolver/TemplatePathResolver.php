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
use Twig\Extension\ExtensionInterface;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-powered template path resolver.
 *
 * Supports full Twig syntax in target_path templates:
 *
 *   Method calls:     {{ object.getKey() }}
 *   Property access:  {{ object.key }}, {{ object.id }}
 *   Null coalescing:  {{ object.getKey()|default('unknown') }}
 *   Custom filters:   {{ value|safe_key }}, {{ items|pluck('key') }}, {{ items|first_of('key') }}
 *   Custom functions: coalesce(a, b, c), prop(obj, 'method', ...args)
 *
 * Core context variables: object, asset, locale, date (DateTimeImmutable), className. Any additional
 * variables come from tagged ContextProviderInterface services (oronts_asset_pilot.context_provider).
 */
class TemplatePathResolver implements PathResolverInterface
{
    protected ?Environment $twig = null;

    /**
     * @param iterable<ExtensionInterface>       $twigExtensions  consumer-tagged Twig extensions
     * @param iterable<ContextProviderInterface> $contextProviders consumer-tagged context providers
     */
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly iterable $twigExtensions = [],
        protected readonly iterable $contextProviders = [],
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

        $path = $this->normalizePath($resolved, $object->getKey());

        $this->logger->debug('Asset Pilot: resolved "{template}" → "{path}" (rule: {rule})', [
            'template' => $rule->targetPath,
            'path' => $path,
            'rule' => $rule->name,
        ]);

        return $path;
    }

    /**
     * Turn a rendered template into a sanitized asset path, collapsing consecutive "unknown"
     * segments. Falls back to /Assets/<key> when the template produced nothing usable, so an
     * empty or all-"unknown" template can never move assets to the asset root.
     */
    protected function normalizePath(string $resolved, ?string $fallbackKey): string
    {
        $segments = [];
        foreach (explode('/', $resolved) as $raw) {
            if ($raw === '') {
                continue;
            }
            $segment = $this->sanitizeSegment($raw);
            if ($segment === 'unknown' && $segments !== [] && end($segments) === 'unknown') {
                continue;
            }
            $segments[] = $segment;
        }

        if (array_filter($segments, static fn (string $s): bool => $s !== 'unknown') === []) {
            $segments = ['Assets', $this->sanitizeSegment($fallbackKey ?? 'unknown')];
        }

        return '/' . implode('/', $segments);
    }

    protected function sanitizeSegment(string $segment): string
    {
        return AssetService::getValidKey($segment, 'asset');
    }

    protected function buildContext(AbstractObject $object, Asset $asset, ?string $locale = null): array
    {
        // Consumer-specific variables (sapId, categories, ...) come from tagged context providers,
        // keeping this generic bundle free of any one consumer's domain fields. Core keys win over
        // provider keys so a provider can never shadow object/asset/date/locale/className.
        $context = [];
        foreach ($this->contextProviders as $provider) {
            $context = [...$context, ...$provider->getContext($object, $asset, $locale)];
        }

        return [
            ...$context,
            'object' => $object,
            'asset' => $asset,
            'date' => new \DateTimeImmutable(),
            'locale' => $locale,
            'className' => $object instanceof Concrete ? $object->getClassName() : 'Folder',
        ];
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

        foreach ($this->twigExtensions as $extension) {
            $this->twig->addExtension($extension);
        }

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
