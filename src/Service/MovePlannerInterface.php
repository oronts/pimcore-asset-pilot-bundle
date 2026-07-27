<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\DriftAssessment;
use Oronts\AssetPilotBundle\Model\MovePlan;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface MovePlannerInterface
{
    public function plan(
        Asset $asset,
        AbstractObject $object,
        Rule $rule,
        string $resolvedPath,
        TriggerType $triggerType,
        bool $dryRun,
    ): MovePlan;

    public function assessDrift(Asset $asset, AbstractObject $object, Rule $rule, string $resolvedPath): DriftAssessment;
}
