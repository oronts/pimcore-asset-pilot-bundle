<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class ReviewedDeliveryRetry
{
    /** @param list<DeadOperationDelivery> $deliveries */
    public function __construct(
        public array $deliveries,
        public ?string $planToken,
        public bool $applied,
    ) {
        if ($deliveries === [] && $planToken !== null) {
            throw new \InvalidArgumentException('An empty delivery retry review cannot have a plan token.');
        }
        if ($applied && $planToken !== null) {
            throw new \InvalidArgumentException('An applied delivery retry cannot return a plan token.');
        }
    }
}
