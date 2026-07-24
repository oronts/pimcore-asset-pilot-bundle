<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;

readonly class PreparedDelivery
{
    public const int MAX_IDENTIFIER_BYTES = 191;

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
        if (strlen($observerId) > self::MAX_IDENTIFIER_BYTES) {
            throw new \InvalidArgumentException('A prepared delivery observer ID cannot exceed 191 bytes.');
        }
        if (trim($deliveryKey) === '') {
            throw new \InvalidArgumentException('A prepared delivery requires a delivery key.');
        }
        if (strlen($deliveryKey) > self::MAX_IDENTIFIER_BYTES) {
            throw new \InvalidArgumentException('A prepared delivery key cannot exceed 191 bytes.');
        }
    }
}
