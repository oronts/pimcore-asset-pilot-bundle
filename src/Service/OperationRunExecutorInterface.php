<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationRunExecution;

interface OperationRunExecutorInterface
{
    public function supports(OperationRunKind $kind): bool;

    /** @param array<string, mixed> $run */
    public function execute(OperationRunKind $kind, string $runId, array $run, ActorContext $actor): OperationRunExecution;
}
