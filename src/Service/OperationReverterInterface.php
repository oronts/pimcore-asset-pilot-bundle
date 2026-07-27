<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\RevertResult;

interface OperationReverterInterface
{
    public function revertById(int $auditId): RevertResult;
}
