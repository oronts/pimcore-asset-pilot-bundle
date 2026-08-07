<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;

readonly class OperationDeliveryAudit
{
    public function __construct(
        public string $deliveryId,
        public int $operationId,
        public string $observerId,
        public OperationDeliveryStatus $status,
        public int $attempts,
        public string $updatedAt,
    ) {
        if ($deliveryId === '' || $observerId === '') {
            throw new \InvalidArgumentException('A delivery audit requires delivery and observer identifiers.');
        }
        if ($operationId <= 0 || $attempts < 0 || $updatedAt === '') {
            throw new \InvalidArgumentException('A delivery audit requires a positive operation ID, non-negative attempts, and update timestamp.');
        }
        if (!in_array($status, [OperationDeliveryStatus::Dead, OperationDeliveryStatus::Delivered], true)) {
            throw new \InvalidArgumentException('Only dead and delivered deliveries require terminal audit reconciliation.');
        }
    }
}
