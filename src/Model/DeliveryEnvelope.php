<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Action\RuleActionDeliveryContextInterface;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;

readonly class DeliveryEnvelope implements RuleActionDeliveryContextInterface
{
    private ?\Closure $leaseHeartbeat;

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
        ?\Closure $leaseHeartbeat = null,
    ) {
        if ($deliveryId === '' || $operationId <= 0 || $deliveryKey === '' || $observerId === '') {
            throw new \InvalidArgumentException('A delivery envelope requires stable delivery and operation identifiers.');
        }
        if ($attempt <= 0 || $claimToken === '') {
            throw new \InvalidArgumentException('A claimed delivery requires a positive attempt and claim token.');
        }

        $this->leaseHeartbeat = $leaseHeartbeat;
    }

    public function deliveryId(): string
    {
        return $this->deliveryId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function heartbeat(): void
    {
        if ($this->leaseHeartbeat !== null && !($this->leaseHeartbeat)()) {
            throw new \RuntimeException('The durable delivery lease was lost.');
        }
    }

    public function withLeaseHeartbeat(\Closure $leaseHeartbeat): self
    {
        return new self(
            $this->deliveryId,
            $this->operationId,
            $this->deliveryKey,
            $this->observerId,
            $this->outcome,
            $this->intent,
            $this->payload,
            $this->attempt,
            $this->claimToken,
            $leaseHeartbeat,
        );
    }
}
