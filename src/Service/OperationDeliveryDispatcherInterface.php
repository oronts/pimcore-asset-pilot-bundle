<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface OperationDeliveryDispatcherInterface
{
    /** @return array{dispatched: list<string>, failed: list<string>} */
    public function dispatchDue(int $limit = 100): array;
}
