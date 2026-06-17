<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use Pimcore\Model\DataObject\AbstractObject;

interface AssetFieldExtractorInterface
{
    /** @return AssetFieldInfo[] */
    public function extract(AbstractObject $object): array;
}
