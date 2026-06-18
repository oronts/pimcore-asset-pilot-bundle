<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Pimcore\Model\Asset;

final class AssetFolders
{
    /**
     * The nearest existing folder at or above $path. Used to check the workspace create ACL before
     * Asset\Service::createFolderByPath() (which would create the tree as a side effect). Returns the
     * root folder when no intermediate ancestor exists.
     */
    public static function nearestExisting(string $path): ?Asset\Folder
    {
        $candidate = '/' . trim($path, '/');
        while ($candidate !== '/') {
            $element = Asset::getByPath($candidate);
            if ($element instanceof Asset\Folder) {
                return $element;
            }
            $candidate = '/' . trim(dirname($candidate), '/');
        }

        $root = Asset::getByPath('/');

        return $root instanceof Asset\Folder ? $root : null;
    }
}
