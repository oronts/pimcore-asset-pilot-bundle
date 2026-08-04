<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Conversion;

/**
 * Overridable seam for resolving a target format to a converter. Alias
 * `AssetConverterResolverInterface` to your own service (or decorate the default) to change converter
 * ordering, add caching, or force a specific converter per format.
 */
interface AssetConverterResolverInterface
{
    /** The first available converter that supports the normalized $targetFormat, or null if none. */
    public function resolve(string $targetFormat): ?AssetConverterInterface;
}
