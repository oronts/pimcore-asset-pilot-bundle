<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Conversion;

use Pimcore\Model\Asset;

/**
 * Pluggable seam for converting an asset's binary to a target format. Tag an implementation
 * `oronts_asset_pilot.asset_converter` and it joins the resolver automatically. The bundle ships a
 * GD-backed default; a consumer can add Imagick, vips, or an external-binary converter for other
 * formats. Callers must degrade gracefully when no available converter supports the requested format.
 */
interface AssetConverterInterface
{
    /** Whether this converter can produce the given normalized target format (e.g. "webp", "jpeg"). */
    public function supports(string $targetFormat): bool;

    /** Whether the converter's runtime dependency (PHP extension or external binary) is present. */
    public function isAvailable(): bool;

    /**
     * Convert the asset's binary to $targetFormat and return the encoded bytes, or null when the source
     * could not be decoded or the target could not be encoded (the caller then degrades gracefully).
     *
     * @param array<string, mixed> $options format-specific settings such as `quality`
     */
    public function convert(Asset $asset, string $targetFormat, array $options): ?string;
}
