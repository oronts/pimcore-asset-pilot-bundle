<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Zip;

/**
 * Options for one archive build: which entry-layout strategy to use (null = configured default) and,
 * for image assets, an optional Pimcore thumbnail config name to pack the derivative instead of the
 * original (non-images and missing thumbnails fall back to the original).
 */
final class ZipBuildOptions
{
    public function __construct(
        public readonly ?string $strategy = null,
        public readonly ?string $thumbnail = null,
    ) {}
}
