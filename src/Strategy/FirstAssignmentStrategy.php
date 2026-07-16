<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

readonly class FirstAssignmentStrategy implements SideEffectFreeConflictStrategyInterface
{
    public const string ASSIGNMENT_PROPERTY = 'asset_pilot_first_assignment';

    public function resolve(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        return $asset->getProperty(self::ASSIGNMENT_PROPERTY) !== true;
    }

    public function supports(MoveStrategy $strategy): bool
    {
        return $strategy === MoveStrategy::FirstAssignment;
    }
}
