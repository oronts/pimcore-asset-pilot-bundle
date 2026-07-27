<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Integrity;

use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

/**
 * Selects the checker used for an asset and applies the same selection policy to live checks.
 */
interface IntegrityCheckerResolverInterface
{
    public function resolve(Asset $asset): ?IntegrityCheckerInterface;

    public function check(Asset $asset): IntegrityResult;
}
