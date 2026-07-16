<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;

final readonly class DeliveryEnvelope
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $deliveryId,
        public int $operationId,
        public string $deliveryKey,
        public string $observerId,
        public OperationDeliveryOutcome $outcome,
        public OperationIntent $intent,
        public array $payload,
        public int $attempt,
        public string $claimToken,
    ) {
        if ($deliveryId === '' || $operationId <= 0 || $deliveryKey === '' || $observerId === '') {
            throw new \InvalidArgumentException('A delivery envelope requires stable delivery and operation identifiers.');
        }
        if ($attempt <= 0 || $claimToken === '') {
            throw new \InvalidArgumentException('A claimed delivery requires a positive attempt and claim token.');
        }
    }
}
