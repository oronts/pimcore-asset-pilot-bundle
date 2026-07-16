<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;

final readonly class PreparedDelivery
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $observerId,
        public string $deliveryKey,
        public OperationDeliveryOutcome $outcome,
        public array $payload = [],
    ) {
        if (trim($observerId) === '') {
            throw new \InvalidArgumentException('A prepared delivery requires an observer ID.');
        }
        if (trim($deliveryKey) === '') {
            throw new \InvalidArgumentException('A prepared delivery requires a delivery key.');
        }
        if (strlen($deliveryKey) > 191) {
            throw new \InvalidArgumentException('A prepared delivery key cannot exceed 191 bytes.');
        }
    }
}
