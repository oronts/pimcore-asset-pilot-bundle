<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ReviewedDeliveryRetry;

interface OperationDeliveryRetryCoordinatorInterface
{
    public function preview(int $limit, ActorContext $actor): ReviewedDeliveryRetry;

    public function apply(int $limit, ActorContext $actor, string $planToken): ReviewedDeliveryRetry;
}
