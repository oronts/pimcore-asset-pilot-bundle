<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Pimcore\Model\Asset;

interface DependencyUsageScannerInterface
{
    public function isReferenced(Asset $asset): bool;
}
