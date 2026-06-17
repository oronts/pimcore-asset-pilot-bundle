<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Pimcore\Tool\Storage;

/**
 * Reads an asset's byte size straight from the asset storage by its full path, mirroring
 * Asset::getFileSize() without loading the full Asset model. Avoids an N+1 of Asset::getById()
 * calls when a query result already carries the path.
 */
final class AssetStorageSize
{
    public static function bytes(string $fullPath): int
    {
        try {
            return Storage::get('asset')->fileSize($fullPath);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function __construct() {}
}
