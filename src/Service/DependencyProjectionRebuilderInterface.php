<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DependencyProjectionBatchResult;

interface DependencyProjectionRebuilderInterface
{
    public function rebuildBatch(int $limit, bool $restart = false): DependencyProjectionBatchResult;
}
