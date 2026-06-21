<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Zip;

use Pimcore\Model\Asset;

/**
 * Decides where an asset lands inside a download archive: the relative entry path (folder + filename)
 * before de-duplication. Implement this and tag the service `oronts_asset_pilot.zip_strategy` (or rely
 * on autoconfiguration) to offer a custom archive layout; it is selected by getName() via the
 * `zip.default_strategy` config key or the `strategy` build option.
 */
interface ZipEntryStrategyInterface
{
    /** Selection key (e.g. 'flat', 'folder', 'type'). */
    public function getName(): string;

    /** Relative path of this asset inside the archive, e.g. "Products/cover.jpg". */
    public function entryPath(Asset $asset): string;
}
