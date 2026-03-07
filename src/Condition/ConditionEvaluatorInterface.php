<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Condition;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface ConditionEvaluatorInterface
{
    public function evaluate(AbstractObject $object, Asset $asset, Rule $rule): bool;
}
