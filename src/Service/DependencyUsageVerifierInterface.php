<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Pimcore\Model\Asset;

interface DependencyUsageVerifierInterface
{
    public function verdict(Asset $asset): DependencyUsageVerdict;
}
