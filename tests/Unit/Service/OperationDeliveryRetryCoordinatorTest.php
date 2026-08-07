<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Exception\DeliveryRetryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\OperationDeliveryRetryCoordinator;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationDeliveryRetryCoordinator::class)]
final class OperationDeliveryRetryCoordinatorTest extends TestCase
{
    #[Test]
    public function rejectsOutOfRangeLimitsWithoutReadingDeliveries(): void
    {
        foreach ([0, 1_001] as $limit) {
            $store = $this->createMock(OperationDeliveryStoreInterface::class);
            $store->expects(self::never())->method('dead');
            $plans = $this->createMock(ApplyPlanServiceInterface::class);

            try {
                $this->coordinator($store, $plans)->preview($limit, ActorContext::system());
                self::fail('An out-of-range delivery retry limit was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    #[Test]
    public function previewIssuesAnActorConfigurationAndFingerprintBoundPlan(): void
    {
        $delivery = $this->delivery();
        $store = $this->createMock(OperationDeliveryStoreInterface::class);
        $store->expects(self::once())->method('dead')->with(25)->willReturn([$delivery]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(
            static fn (ApplyPlan $plan): bool => $plan->kind === 'delivery-retry'
                && $plan->actor->type === ActorType::System
                && $plan->request === ['limit' => 25]
                && $plan->config === ['operation_journal' => ['max_attempts' => 5]]
                && $plan->targets[0]->id === $delivery->deliveryId
                && $plan->targets[0]->fingerprint === $delivery->fingerprint,
        ))->willReturn('signed');

        $review = $this->coordinator($store, $plans)->preview(25, ActorContext::system());

        self::assertSame([$delivery], $review->deliveries);
        self::assertSame('signed', $review->planToken);
        self::assertFalse($review->applied);
    }

    #[Test]
    public function applyClaimsBeforeRequeueingTheExactReviewedRows(): void
    {
        $delivery = $this->delivery();
        $store = $this->createMock(OperationDeliveryStoreInterface::class);
        $store->method('dead')->willReturn([$delivery]);
        $store->expects(self::once())->method('requeueDead')->with([$delivery])->willReturn(1);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed', self::isInstanceOf(ApplyPlan::class))
            ->willReturn(ApplyPlanStatus::Claimed);

        $review = $this->coordinator($store, $plans)->apply(100, ActorContext::system(), 'signed');

        self::assertTrue($review->applied);
        self::assertNull($review->planToken);
        self::assertSame([$delivery], $review->deliveries);
    }

    #[Test]
    public function applyRejectsAChangedPlanBeforeRequeue(): void
    {
        $store = $this->createMock(OperationDeliveryStoreInterface::class);
        $store->method('dead')->willReturn([$this->delivery()]);
        $store->expects(self::never())->method('requeueDead');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Stale);

        $this->expectException(DeliveryRetryPlanException::class);
        $this->coordinator($store, $plans)->apply(100, ActorContext::system(), 'stale');
    }

    private function coordinator(
        OperationDeliveryStoreInterface $store,
        ApplyPlanServiceInterface $plans,
    ): OperationDeliveryRetryCoordinator {
        return new OperationDeliveryRetryCoordinator(
            $store,
            $plans,
            ['operation_journal' => ['max_attempts' => 5]],
        );
    }

    private function delivery(): DeadOperationDelivery
    {
        return new DeadOperationDelivery(
            str_repeat('d', 64),
            91,
            'observer:success',
            'observer',
            OperationDeliveryOutcome::Success,
            5,
            'unavailable',
            '2026-07-15 10:00:00',
            str_repeat('f', 64),
        );
    }
}
