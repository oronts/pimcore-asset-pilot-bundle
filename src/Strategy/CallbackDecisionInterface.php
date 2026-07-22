<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface CallbackDecisionInterface
{
    public function decide(Asset $asset, AbstractObject $object, Rule $rule, bool $dryRun): bool;
}
