<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

readonly class RuleMatch
{
    public function __construct(
        public Rule $rule,
        public AbstractObject $object,
        public Asset $asset,
        public string $resolvedPath,
        public ?string $locale = null,
    ) {}
}
