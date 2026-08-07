<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Pimcore\Model\Asset;

class AssetProtection
{
    /**
     * Default name of the Pimcore property that, when truthy on an asset, excludes it from
     * organization. The runtime value is `oronts_asset_pilot.protection.lock_property` (config);
     * this constant is the single source for that config default and the service fallbacks.
     */
    public const string DEFAULT_LOCK_PROPERTY = 'asset_pilot_locked';

    /**
     * Whether the asset is locked against any automated move/delete: the lock property is set and
     * truthy. Single source so every destructive path (organize move, cleanup, quarantine, purge)
     * honors the lock identically.
     */
    public static function isLocked(Asset $asset, string $lockProperty): bool
    {
        return $asset->hasProperty($lockProperty) && (bool) $asset->getProperty($lockProperty);
    }
}
