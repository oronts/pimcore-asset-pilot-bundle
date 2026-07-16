<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;

final readonly class DeadOperationDelivery
{
    public function __construct(
        public string $deliveryId,
        public int $operationId,
        public string $deliveryKey,
        public string $observerId,
        public OperationDeliveryOutcome $outcome,
        public int $attempts,
        public ?string $lastError,
        public string $updatedAt,
        public string $fingerprint,
    ) {
        if ($deliveryId === '' || $deliveryKey === '' || $observerId === '') {
            throw new \InvalidArgumentException('A dead delivery requires delivery, key, and observer identifiers.');
        }
        if ($operationId <= 0 || $attempts < 0) {
            throw new \InvalidArgumentException('A dead delivery requires a positive operation ID and non-negative attempts.');
        }
        if ($updatedAt === '' || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('A dead delivery requires an update timestamp and SHA-256 fingerprint.');
        }
    }
}
