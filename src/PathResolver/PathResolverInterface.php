<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\PathResolver;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface PathResolverInterface
{
    public function resolve(
        AbstractObject $object,
        Asset $asset,
        Rule $rule,
        ?string $locale = null,
    ): string;
}
