<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Exception\DeliveryRetryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Model\ReviewedDeliveryRetry;
use Oronts\AssetPilotBundle\Support\BulkIds;

readonly class OperationDeliveryRetryCoordinator implements OperationDeliveryRetryCoordinatorInterface
{
    /** @param array<string, mixed> $planConfiguration */
    public function __construct(
        private OperationDeliveryStoreInterface $deliveries,
        private ApplyPlanServiceInterface $plans,
        private array $planConfiguration,
    ) {}

    public function preview(int $limit, ActorContext $actor): ReviewedDeliveryRetry
    {
        $limit = $this->limit($limit);
        $deliveries = $this->deliveries->dead($limit);

        return new ReviewedDeliveryRetry(
            $deliveries,
            $deliveries === [] ? null : $this->plans->issue($this->plan($limit, $actor, $deliveries)),
            false,
        );
    }

    public function apply(int $limit, ActorContext $actor, string $planToken): ReviewedDeliveryRetry
    {
        $limit = $this->limit($limit);
        $deliveries = $this->deliveries->dead($limit);
        if ($deliveries === []) {
            throw new DeliveryRetryPlanException(ApplyPlanStatus::Stale);
        }

        $claim = $this->plans->claim($planToken, $this->plan($limit, $actor, $deliveries));
        if ($claim !== ApplyPlanStatus::Claimed) {
            throw new DeliveryRetryPlanException($claim);
        }

        try {
            $requeued = $this->deliveries->requeueDead($deliveries);
        } catch (\LogicException) {
            throw new DeliveryRetryPlanException(ApplyPlanStatus::Stale);
        }
        if ($requeued !== count($deliveries)) {
            throw new DeliveryRetryPlanException(ApplyPlanStatus::Stale);
        }

        return new ReviewedDeliveryRetry($deliveries, null, true);
    }

    /** @param list<DeadOperationDelivery> $deliveries */
    private function plan(int $limit, ActorContext $actor, array $deliveries): ApplyPlan
    {
        return new ApplyPlan(
            'delivery-retry',
            $actor,
            ['limit' => $limit],
            $this->planConfiguration,
            array_map(
                static fn (DeadOperationDelivery $delivery): ApplyPlanTarget => new ApplyPlanTarget(
                    $delivery->deliveryId,
                    $delivery->fingerprint,
                ),
                $deliveries,
            ),
        );
    }

    private function limit(int $limit): int
    {
        if ($limit < 1 || $limit > BulkIds::MAX) {
            throw new \InvalidArgumentException(sprintf('Delivery retry limit must be between 1 and %d.', BulkIds::MAX));
        }

        return $limit;
    }
}
