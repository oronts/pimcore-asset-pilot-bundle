<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ReviewedOperationRecovery;

interface OperationRecoveryCoordinatorInterface
{
    public function preview(int $limit, ActorContext $actor): ReviewedOperationRecovery;

    public function apply(int $limit, ActorContext $actor, string $planToken): ReviewedOperationRecovery;
}
