<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

final class AssetProtection
{
    /**
     * Default name of the Pimcore property that, when truthy on an asset, excludes it from
     * organization. The runtime value is `oronts_asset_pilot.protection.lock_property` (config);
     * this constant is the single source for that config default and the service fallbacks.
     */
    public const string DEFAULT_LOCK_PROPERTY = 'asset_pilot_locked';
}
