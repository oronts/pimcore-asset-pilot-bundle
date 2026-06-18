<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\PathResolver;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

/**
 * Contributes extra variables to the path-template context. Consumers tag an implementation with
 * `oronts_asset_pilot.context_provider` to expose their own domain values (e.g. productCode, region)
 * to target_path templates, instead of the bundle hard-coding consumer-specific fields.
 */
interface ContextProviderInterface
{
    /** @return array<string, mixed> extra Twig variables keyed by template variable name */
    public function getContext(AbstractObject $object, Asset $asset, ?string $locale): array;
}
