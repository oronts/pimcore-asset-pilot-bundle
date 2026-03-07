<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface ConflictStrategyInterface
{
    public function resolve(Asset $asset, AbstractObject $object, Rule $rule): bool;

    public function supports(MoveStrategy $strategy): bool;
}
