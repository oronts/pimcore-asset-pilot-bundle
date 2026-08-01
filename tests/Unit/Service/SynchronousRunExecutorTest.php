<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\BulkRunOutcomeKind;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\SingleRunOutcomeKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\LostRunItemOwnershipException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrain;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrainInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use Oronts\AssetPilotBundle\Service\SynchronousRunExecutor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

final class SynchronousRunExecutorTest extends TestCase
{
    private const string RUN = 'run-1';

    #[Test]
    public function executeSingleReturnsClaimConflictWithoutOrganizingWhenTheLeaseCannotStart(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->expects(self::never())->method('organizeWithHeartbeat');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('finish');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(false);
        $lease->expects(self::never())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::ClaimConflict, $outcome->kind);
    }

    #[Test]
    public function executeSingleReturnsClaimConflictWhenStartingTheLeaseThrows(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->expects(self::never())->method('organizeWithHeartbeat');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willThrowException(new \RuntimeException('db error claiming the item'));

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::ClaimConflict, $outcome->kind);
    }

    #[Test]
    public function executeSingleCompletesAndFinishesTheRun(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willReturn([]);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('finish')->with(self::RUN)->willReturn(OperationRunStatus::Completed);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $lease->expects(self::once())->method('release')->with(self::RUN, 'object:42');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::Completed, $outcome->kind);
        self::assertSame([], $outcome->results);
    }

    #[Test]
    public function executeSingleDrainsACoalescedSaveUnderTheRunActor(): void
    {
        // A save that coalesced into this synchronous reviewed run marked the object dirty; the executor must
        // drain it (queue a fresh non-fingerprinted organize) under the run's actor, mirroring the async handlers.
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willReturn([]);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willReturn(OperationRunStatus::Completed);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $actor = ActorContext::user(7);
        $drain = $this->createMock(ObjectSaveDrainInterface::class);
        $drain->expects(self::once())->method('drain')->with(42, TriggerType::ObjectSave, $actor);

        $this->executor($organizer, $runs, $lease, $drain)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', $actor);
    }

    #[Test]
    public function executeBulkDrainsEveryCompletedObjectUnderTheRunActor(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, ?callable $progress, ?int $dispatchedAt, ?callable $stale, ?callable $cancel, ?callable $before, array $fingerprints, ?callable $heartbeat, ?callable $after): BulkOrganizeReport {
                foreach ([1, 2] as $id) {
                    $before($id);
                    $after(new BulkObjectResult($id, BulkObjectStatus::Succeeded, operationCount: 1));
                }

                return new BulkOrganizeReport([], []);
            },
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willReturn(OperationRunStatus::Completed);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $actor = ActorContext::user(7);
        $drained = [];
        $drain = $this->createMock(ObjectSaveDrainInterface::class);
        $drain->expects(self::exactly(2))->method('drain')->willReturnCallback(
            static function (int $id, TriggerType $trigger, ActorContext $a) use (&$drained, $actor): void {
                self::assertSame(TriggerType::ObjectSave, $trigger);
                self::assertSame($actor, $a);
                $drained[] = $id;
            },
        );

        $this->executor($organizer, $runs, $lease, $drain)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], $actor);
        self::assertSame([1, 2], $drained);
    }

    #[Test]
    public function executeBulkDrainsBeforeCompletingSoAThrowingCompletionCannotSkipTheDrain(): void
    {
        // Guards the drain-before-complete ordering: if completion throws, the coalesced save must already be drained.
        $drained = [];
        $drain = $this->createMock(ObjectSaveDrainInterface::class);
        $drain->method('drain')->willReturnCallback(static function (int $id) use (&$drained): void {
            $drained[] = $id;
        });
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, ?callable $progress, ?int $dispatchedAt, ?callable $stale, ?callable $cancel, ?callable $before, array $fingerprints, ?callable $heartbeat, ?callable $after): BulkOrganizeReport {
                $before(1);
                $after(new BulkObjectResult(1, BulkObjectStatus::Succeeded, operationCount: 1));

                return new BulkOrganizeReport([], []);
            },
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('token')->willReturn('tok');
        $lease->method('complete')->willThrowException(new \RuntimeException('completion failed'));

        $this->executor($organizer, $runs, $lease, $drain)->executeBulk(self::RUN, [1], TriggerType::Api, [], ActorContext::user(7));

        self::assertSame([1], $drained, 'the drain ran before the completion threw');
    }

    #[Test]
    public function executeSingleReturnsLeaseLostWhenCompletionFails(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willReturn([]);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('finish');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(false);

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeSingleReturnsStaleOnAStaleApplyPlan(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new StaleApplyPlanException('stale'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('finish')->willReturn(OperationRunStatus::Completed);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->expects(self::once())->method('complete')->willReturn(true);

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::Stale, $outcome->kind);
    }

    #[Test]
    public function executeSingleReturnsLeaseLostWhenAStaleCompletionLosesOwnership(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new StaleApplyPlanException('stale'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('finish');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(false);

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeSingleReturnsFailedAndFailsTheRunOnAnError(): void
    {
        $error = new \RuntimeException('boom');
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException($error);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('fail')->with(self::RUN, 'Organization failed.');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $lease->expects(self::once())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::Failed, $outcome->kind);
        self::assertSame($error, $outcome->cause);
    }

    #[Test]
    public function executeSingleReturnsLeaseLostWhenAnErrorCompletionLosesOwnership(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new \RuntimeException('boom'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(false);

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeSingleReportsLeaseLostWhenTheSuccessfulCompletionThrows(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willReturn([]);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $completeCalls = 0;
        $lease->method('complete')->willReturnCallback(function () use (&$completeCalls): bool {
            ++$completeCalls;
            if ($completeCalls === 1) {
                throw new \RuntimeException('db error recording a successful completion');
            }

            return true;
        });
        $lease->expects(self::once())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
        self::assertSame(1, $completeCalls, 'A successful item must not be re-completed as failed.');
    }

    #[Test]
    public function executeSingleReportsLeaseLostWhenTheStalePlanCompletionThrows(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new StaleApplyPlanException('stale'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->expects(self::once())->method('complete')->willThrowException(new \RuntimeException('db error recording the skip'));
        $lease->expects(self::once())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeBulkCompletesWithTheReport(): void
    {
        $report = $this->createMock(BulkOrganizeReport::class);
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willReturn($report);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('finish')->with(self::RUN)->willReturn(OperationRunStatus::Completed);
        $lease = $this->createMock(RunItemLease::class);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::Completed, $outcome->kind);
        self::assertSame($report, $outcome->report);
    }

    #[Test]
    public function executeBulkReturnsOwnershipLostWithoutFinishingTheRun(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willThrowException(new LostRunItemOwnershipException('lost'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('finish');
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::OwnershipLost, $outcome->kind);
    }

    #[Test]
    public function executeBulkCleanupCompletesOnlyTheTokenOwnedItem(): void
    {
        // On a mid-loop failure only the one still-owned item holds a token; never-started/released items
        // (null token) must not be terminalized. The run is then failed once.
        $error = new \RuntimeException('boom');
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willThrowException($error);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('fail')->with(self::RUN, 'Bulk organization failed.');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('token')->willReturnMap([
            [self::RUN, 'object:1', null],
            [self::RUN, 'object:2', 'tok'],
            [self::RUN, 'object:3', null],
        ]);
        $lease->expects(self::once())->method('complete')
            ->with(self::RUN, 'object:2', OperationRunItemStatus::Failed, [], 'Bulk organization failed.')
            ->willReturn(true);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2, 3], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::Failed, $outcome->kind);
        self::assertSame($error, $outcome->cause);
    }

    #[Test]
    public function executeBulkReportsOwnershipLostWhenCleanupLosesTheFence(): void
    {
        // The owned item's fenced completion returns false: a concurrent attempt reclaimed it and records it,
        // so this attempt must not fail the run it no longer owns.
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willThrowException(new \RuntimeException('boom'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('token')->willReturnMap([[self::RUN, 'object:1', 'tok']]);
        $lease->method('complete')->willReturn(false);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::OwnershipLost, $outcome->kind);
    }

    #[Test]
    public function executeBulkReportsOwnershipLostWhenTheRunConcurrentlyFinalizedFailed(): void
    {
        $report = $this->createMock(BulkOrganizeReport::class);
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willReturn($report);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willReturn(OperationRunStatus::Failed);
        $lease = $this->createMock(RunItemLease::class);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::OwnershipLost, $outcome->kind);
    }

    #[Test]
    public function executeBulkReportsCompletedWhenTheRunFinalizesPartial(): void
    {
        $report = $this->createMock(BulkOrganizeReport::class);
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willReturn($report);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willReturn(OperationRunStatus::Partial);
        $lease = $this->createMock(RunItemLease::class);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::Completed, $outcome->kind);
        self::assertSame($report, $outcome->report);
    }

    #[Test]
    public function executeSingleReportsLeaseLostWhenParentFinalizationThrowsAfterTheItemCompleted(): void
    {
        // The item is durably terminal; a throwing parent finish() is indeterminate finalization, not a failure.
        // reconcileUnfinalizedRuns derives the parent status from the item outcomes, so the run must NOT be failed.
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willReturn([]);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willThrowException(new \RuntimeException('finalize boom'));
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->expects(self::once())->method('complete')->willReturn(true);
        $lease->expects(self::once())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeSingleReportsLeaseLostWhenFailedItemCompletionThrows(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new \RuntimeException('boom'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willThrowException(new \RuntimeException('db error recording the failure'));
        $lease->expects(self::once())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeSingleReportsLeaseLostWhenTheRunWideFailThrows(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new \RuntimeException('boom'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('fail')->willThrowException(new \RuntimeException('db error failing the run'));
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $lease->expects(self::once())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeSingleReportsLeaseLostWhenTheRunConcurrentlyFinalizedFailed(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willReturn([]);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willReturn(OperationRunStatus::Failed);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $lease->expects(self::once())->method('release');

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    #[Test]
    public function executeBulkReportsOwnershipLostWhenCleanupCompletionThrows(): void
    {
        // A throwing completion cannot prove ownership, so the run must not be failed by an unfenced fail().
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willThrowException(new \RuntimeException('boom'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('token')->willReturnMap([[self::RUN, 'object:1', 'tok']]);
        $lease->expects(self::once())->method('release')->with(self::RUN, 'object:1');
        $lease->method('complete')->willThrowException(new \RuntimeException('cleanup db error'));

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::OwnershipLost, $outcome->kind);
    }

    #[Test]
    public function executeBulkReportsOwnershipLostWhenParentFinalizationThrows(): void
    {
        // Every item completed and released its token; a throwing parent finish() must not fail the run.
        $report = $this->createMock(BulkOrganizeReport::class);
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willReturn($report);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willThrowException(new \RuntimeException('bulk finalize boom'));
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('token')->willReturn(null);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::OwnershipLost, $outcome->kind);
    }

    #[Test]
    public function executeBulkReportsOwnershipLostWhenTheRunDidNotFinalize(): void
    {
        $report = $this->createMock(BulkOrganizeReport::class);
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeBulkDetailed')->willReturn($report);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willReturn(OperationRunStatus::Running);
        $lease = $this->createMock(RunItemLease::class);

        $outcome = $this->executor($organizer, $runs, $lease)->executeBulk(self::RUN, [1, 2], TriggerType::Api, [], ActorContext::system());

        self::assertSame(BulkRunOutcomeKind::OwnershipLost, $outcome->kind);
    }

    #[Test]
    public function executeSingleReportsLeaseLostWhenAStaleRunConcurrentlyFinalizedFailed(): void
    {
        $organizer = $this->createMock(AssetOrganizerInterface::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new StaleApplyPlanException('stale'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('finish')->willReturn(OperationRunStatus::Failed);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);

        $outcome = $this->executor($organizer, $runs, $lease)->executeSingle(self::RUN, $this->object(), TriggerType::Api, 'fp', ActorContext::system());

        self::assertSame(SingleRunOutcomeKind::LeaseLost, $outcome->kind);
    }

    private function executor(AssetOrganizerInterface $organizer, OperationRunStoreInterface $runs, RunItemLease $lease, ?ObjectSaveDrainInterface $drain = null): SynchronousRunExecutor
    {
        return new SynchronousRunExecutor($organizer, $runs, $lease, $drain ?? new ObjectSaveDrain($this->createMock(LoopGuard::class), $this->createMock(OrganizeDispatcherInterface::class), new NullLogger()));
    }

    private function object(): AbstractObject
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(42);

        return $object;
    }
}
