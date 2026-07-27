<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

use Oronts\AssetPilotBundle\Model\MoveOperation;

interface AuditWriterInterface
{
    public function log(MoveOperation $operation): void;
}
