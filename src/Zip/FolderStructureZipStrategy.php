<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Zip;

use Pimcore\Model\Asset;

/** Mirrors the Pimcore asset folder tree inside the archive (e.g. "Products/2024/cover.jpg"). */
class FolderStructureZipStrategy implements ZipEntryStrategyInterface
{
    public function getName(): string
    {
        return 'folder';
    }

    public function entryPath(Asset $asset): string
    {
        return ltrim((string) $asset->getRealFullPath(), '/');
    }
}
