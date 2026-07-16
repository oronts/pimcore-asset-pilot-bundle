<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Exception\OperationRecoveryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\OperationRecoveryResult;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\OperationRecoveryCoordinator;
use Oronts\AssetPilotBundle\Service\OperationRecoveryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationRecoveryCoordinator::class)]
final class OperationRecoveryCoordinatorTest extends TestCase
{
    #[Test]
    public function rejectsOutOfRangeLimitsWithoutReadingRecoveryState(): void
    {
        foreach ([0, 1_001] as $limit) {
            $recovery = $this->createMock(OperationRecoveryService::class);
            $recovery->expects(self::never())->method('preview');
            $plans = $this->createMock(ApplyPlanServiceInterface::class);

            try {
                $this->coordinator($recovery, $plans)->preview($limit, ActorContext::system());
                self::fail('An out-of-range recovery limit was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    #[Test]
    public function previewIssuesAnActorAndConfigurationBoundPlan(): void
    {
        $result = $this->recoveryResult(OperationStatus::Completed, false);
        $recovery = $this->createMock(OperationRecoveryService::class);
        $recovery->expects(self::once())->method('preview')->with(25)->willReturn([$result]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(
            static fn (ApplyPlan $plan): bool => $plan->kind === 'operation-recovery'
                && $plan->actor->type === ActorType::User
                && $plan->actor->userId === 17
                && $plan->request === ['limit' => 25]
                && $plan->config === ['operation_journal' => ['recovery_after_seconds' => 900]]
                && $plan->targets[0]->id === '91'
                && $plan->targets[0]->fingerprint === str_repeat('a', 64),
        ))->willReturn('signed');

        $review = $this->coordinator($recovery, $plans)->preview(25, ActorContext::user(17));

        self::assertSame('signed', $review->planToken);
        self::assertFalse($review->applied);
        self::assertSame([$result], $review->results);
    }

    #[Test]
    public function emptyPreviewDoesNotIssueAToken(): void
    {
        $recovery = $this->createMock(OperationRecoveryService::class);
        $recovery->method('preview')->willReturn([]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::never())->method('issue');

        $review = $this->coordinator($recovery, $plans)->preview(100, ActorContext::system());

        self::assertNull($review->planToken);
        self::assertSame([], $review->results);
    }

    #[Test]
    public function applyClaimsBeforeFinalizingTheExactReviewedIds(): void
    {
        $preview = $this->recoveryResult(OperationStatus::Completed, false);
        $resolved = $this->recoveryResult(OperationStatus::Completed, true);
        $recovery = $this->createMock(OperationRecoveryService::class);
        $recovery->method('preview')->willReturn([$preview]);
        $recovery->expects(self::once())->method('recover')->with(100, [91])->willReturn([$resolved]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed', self::isInstanceOf(ApplyPlan::class))->willReturn(ApplyPlanStatus::Claimed);

        $review = $this->coordinator($recovery, $plans)->apply(100, ActorContext::user(17), 'signed');

        self::assertTrue($review->applied);
        self::assertNull($review->planToken);
        self::assertSame([$resolved], $review->results);
    }

    #[Test]
    public function applyRejectsMalformedOrChangedPlansBeforeRecovery(): void
    {
        $recovery = $this->createMock(OperationRecoveryService::class);
        $recovery->method('preview')->willReturn([$this->recoveryResult(OperationStatus::Completed, false)]);
        $recovery->expects(self::never())->method('recover');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Malformed);

        $this->expectException(OperationRecoveryPlanException::class);
        $this->coordinator($recovery, $plans)->apply(100, ActorContext::user(17), 'bad');
    }

    #[Test]
    public function applyRejectsAConcurrentlyResolvedReviewedOperation(): void
    {
        $recovery = $this->createMock(OperationRecoveryService::class);
        $recovery->method('preview')->willReturn([$this->recoveryResult(OperationStatus::Completed, false)]);
        $recovery->method('recover')->willReturn([]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Claimed);

        $this->expectException(OperationRecoveryPlanException::class);
        $this->coordinator($recovery, $plans)->apply(100, ActorContext::user(17), 'signed');
    }

    private function coordinator(
        OperationRecoveryService $recovery,
        ApplyPlanServiceInterface $plans,
    ): OperationRecoveryCoordinator {
        return new OperationRecoveryCoordinator(
            $recovery,
            $plans,
            ['operation_journal' => ['recovery_after_seconds' => 900]],
        );
    }

    private function recoveryResult(OperationStatus $status, bool $updated): OperationRecoveryResult
    {
        return new OperationRecoveryResult(91, 7, OperationKind::Move, $status, $updated, 'classification', str_repeat('a', 64));
    }
}
