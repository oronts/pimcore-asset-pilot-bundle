<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Conversion;

/**
 * Resolves a normalized target format to the first tagged converter that both supports it and is
 * available at runtime. Returns null when nothing can produce the format, so the caller degrades
 * gracefully rather than failing the organize.
 */
class AssetConverterResolver implements AssetConverterResolverInterface
{
    /** @param iterable<AssetConverterInterface> $converters */
    public function __construct(protected readonly iterable $converters) {}

    public function resolve(string $targetFormat): ?AssetConverterInterface
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($targetFormat) && $converter->isAvailable()) {
                return $converter;
            }
        }

        return null;
    }
}
