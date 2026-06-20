<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Zip;

use Pimcore\Model\Asset;

/** Groups assets into one folder per asset type (e.g. "image/cover.jpg", "document/spec.pdf"). */
final class AssetTypeZipStrategy implements ZipEntryStrategyInterface
{
    public function getName(): string
    {
        return 'type';
    }

    public function entryPath(Asset $asset): string
    {
        $type = $asset->getType() !== '' ? $asset->getType() : 'other';

        return $type . '/' . $asset->getFilename();
    }
}
