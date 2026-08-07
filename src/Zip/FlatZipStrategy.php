<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Zip;

use Pimcore\Model\Asset;

/** All assets at the archive root, by filename (collisions de-duplicated by the builder). */
class FlatZipStrategy implements ZipEntryStrategyInterface
{
    public function getName(): string
    {
        return 'flat';
    }

    public function entryPath(Asset $asset): string
    {
        return (string) $asset->getFilename();
    }
}
