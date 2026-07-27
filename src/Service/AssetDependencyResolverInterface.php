<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface AssetDependencyResolverInterface
{
    /** @return list<int> */
    public function dependentObjectIds(int $assetId, int $limit = 100): array;
}
