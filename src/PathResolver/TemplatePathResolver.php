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
        // Consumer-specific variables (productCode, region, ...) come from tagged context providers,
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

        foreach ($this->twigExtensions as $extension) {
            $this->twig->addExtension($extension);
        }

        // Register the built-ins last: Twig initializes extensions in order and the last one wins a
        // name collision, so this keeps the built-in filters/functions authoritative over consumer
        // extensions, matching the prior addFilter-based precedence.
        $this->twig->addExtension(new PathTemplateExtension());

        return $this->twig;
    }
}
