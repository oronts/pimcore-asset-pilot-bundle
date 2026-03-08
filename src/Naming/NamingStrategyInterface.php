<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Naming;

use Pimcore\Model\Asset;

interface NamingStrategyInterface
{
    public function generateName(Asset $asset, string $targetPath): string;
}
