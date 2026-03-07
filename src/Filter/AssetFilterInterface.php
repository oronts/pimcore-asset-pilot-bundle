<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Filter;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface AssetFilterInterface
{
    public function accept(Asset $asset, AbstractObject $object, Rule $rule): bool;
}
