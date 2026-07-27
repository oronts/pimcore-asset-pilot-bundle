<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;

interface OperationDeliveryProcessorInterface
{
    public function process(string $deliveryId): ?OperationDeliveryStatus;

    /** @return array<string, OperationDeliveryStatus|null> */
    public function processDue(int $limit = 100): array;
}
