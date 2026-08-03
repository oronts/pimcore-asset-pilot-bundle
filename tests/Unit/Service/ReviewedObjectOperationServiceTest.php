<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrain;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationService;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use Oronts\AssetPilotBundle\Tests\Support\InMemoryApplyPlanClaimStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;

#[CoversClass(ReviewedObjectOperationService::class)]
final class ReviewedObjectOperationServiceTest extends TestCase
{
    private ActorContextStore $actorStore;

    #[Test]
    public function reviewedExecutionRunsPreviewAndApplyUnderTheExplicitActorNotAmbient(): void
    {
        $actor = ActorContext::user(7);
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $seenDuringPreview = null;
        $seenDuringApply = null;

        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturnCallback(function () use (&$seenDuringPreview, $operation): array {
            $seenDuringPreview = $this->actorStore->current();

            return [$operation];
        });
        $report = new BulkOrganizeReport([], [new BulkObjectResult(42, BulkObjectStatus::Succeeded, operationCount: 1)]);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(function () use (&$seenDuringApply, $report): BulkOrganizeReport {
            $seenDuringApply = $this->actorStore->current();

            return $report;
        });
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('sync-run');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('start')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('startItem')->willReturn(true);
        $runs->method('finish')->willReturn(OperationRunStatus::Completed);
        $service = $this->service([$object], $organizer, $dispatcher, $runs);
        $selector = ['assetIds' => [10], 'mode' => 'asset_ids'];

        $preview = $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, true, false, actor: $actor);
        self::assertInstanceOf(ActorContext::class, $seenDuringPreview);
        self::assertSame(ActorType::User, $seenDuringPreview->type);
        self::assertSame(7, $seenDuringPreview->userId);

        $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, false, false, $preview->planToken, $actor);
        self::assertInstanceOf(ActorContext::class, $seenDuringApply);
        self::assertSame(ActorType::User, $seenDuringApply->type);
        self::assertSame(7, $seenDuringApply->userId);
    }

    #[Test]
    public function previewIssuesSignedPlanWithoutCreatingOrExecutingARun(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('dryRun')->with($object, TriggerType::Manual)->willReturn([$operation]);
        $organizer->expects(self::never())->method('organizeBulkDetailed');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $dispatcher->expects(self::never())->method('dispatchBulk');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('start');
        $service = $this->service([$object], $organizer, $dispatcher, $runs);

        $result = $service->execute(
            OperationRunKind::Reorganize,
            [42],
            ['folder' => '/Staging', 'limit' => 25],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );

        self::assertTrue($result->dryRun);
        self::assertNotNull($result->planToken);
        self::assertSame([$operation], $result->operations);
        self::assertNull($result->runId);
    }

    #[Test]
    public function changedObjectFingerprintRejectsApplyBeforeRunCreation(): void
    {
        $modifiedAt = 100;
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(42);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getRealFullPath')->willReturn('/products/42');
        $object->method('getModificationDate')->willReturnCallback(static function () use (&$modifiedAt): int {
            return $modifiedAt;
        });
        $object->method('getType')->willReturn('object');
        $object->method('getVersionCount')->willReturn(1);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([$object], $organizer, $dispatcher);
        $preview = $service->execute(
            OperationRunKind::Reorganize,
            [42],
            ['folder' => '/Staging'],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );
        $modifiedAt = 101;

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            OperationRunKind::Reorganize,
            [42],
            ['folder' => '/Staging'],
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        );
    }

    #[Test]
    public function changedSelectorRejectsApplyBeforeRunCreation(): void
    {
        $object = $this->object(42);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([$object], $organizer, $dispatcher);
        $preview = $service->execute(
            OperationRunKind::Replay,
            [42],
            ['filters' => ['rule_name' => 'images'], 'limit' => 10],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            OperationRunKind::Replay,
            [42],
            ['filters' => ['rule_name' => 'other'], 'limit' => 10],
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        );
    }

    #[Test]
    public function changedResolvedObjectSetRejectsApplyBeforeRunCreation(): void
    {
        $first = $this->object(42);
        $second = $this->object(43);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturnCallback(
            fn (AbstractObject $object): array => [$this->operation((int) $object->getId(), 10 + (int) $object->getId())],
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([$first, $second], $organizer, $dispatcher);
        $selector = ['folder' => '/Staging', 'limit' => 25];
        $preview = $service->execute(
            OperationRunKind::Reorganize,
            [42, 43],
            $selector,
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            OperationRunKind::Reorganize,
            [42],
            $selector,
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        );
    }

    #[Test]
    public function emptyEligibleSelectionRejectsApplyAsStale(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('dryRun');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([], $organizer, $dispatcher);

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            OperationRunKind::Organize,
            [42],
            ['mode' => 'object_id', 'objectId' => 42],
            TriggerType::Manual,
            false,
            true,
            'signed-plan',
            ActorContext::system(),
        );
    }

    #[Test]
    public function reusedTokenCannotDispatchASecondBatch(): void
    {
        $object = $this->object(42);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->willReturn('run-1');
        $dispatcher->expects(self::once())->method('dispatchBulk')->willReturn('run-1');
        $service = $this->service([$object], $organizer, $dispatcher);
        $preview = $service->execute(
            OperationRunKind::Replay,
            [42],
            ['filters' => [], 'limit' => 10],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );
        $applyArguments = [
            OperationRunKind::Replay,
            [42],
            ['filters' => [], 'limit' => 10],
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        ];
        $service->execute(...$applyArguments);

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(...$applyArguments);
    }

    #[Test]
    public function asyncApplyCarriesExactKindRequestFingerprintsAndSystemActor(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $request = [
            'objectIds' => [42],
            'operations' => [[
                'assetId' => 10,
                'error' => null,
                'objectClass' => 'Product',
                'objectId' => 42,
                'ruleName' => 'product-assets',
                'sourcePath' => '/incoming/10.jpg',
                'status' => 'pending',
                'targetPath' => '/organized/10.jpg',
            ]],
            'selector' => ['filters' => ['rule_name' => 'images'], 'limit' => 10],
            'trigger' => TriggerType::Manual->value,
        ];
        $actor = ActorContext::system();
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->with(
            [42],
            TriggerType::Manual,
            self::callback(static fn (ActorContext $actual): bool => $actual->type === ActorType::System),
            [42 => $fingerprint],
            OperationRunKind::Replay,
            $request,
        )->willReturn('replay-run');
        $dispatcher->expects(self::once())->method('dispatchBulk')->with(
            [42],
            TriggerType::Manual,
            $actor,
            'replay-run',
            [42 => $fingerprint],
        )->willReturn('replay-run');
        $service = $this->service([$object], $organizer, $dispatcher);
        $selector = ['filters' => ['rule_name' => 'images'], 'limit' => 10];
        $preview = $service->execute(OperationRunKind::Replay, [42], $selector, TriggerType::Manual, true, false, actor: $actor);

        $result = $service->execute(
            OperationRunKind::Replay,
            [42],
            $selector,
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            $actor,
        );

        self::assertSame('replay-run', $result->runId);
        self::assertSame(OperationRunStatus::Queued, $result->runStatus);
        self::assertSame(1, $result->dispatched);
    }

    #[Test]
    public function synchronousApplyCreatesStartsCompletesAndFinishesRun(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $report = new BulkOrganizeReport([], [new BulkObjectResult(42, BulkObjectStatus::Succeeded, operationCount: 1)]);
        $organizer->expects(self::once())->method('organizeBulkDetailed')->with(
            [42],
            TriggerType::Manual,
            null,
            null,
            null,
            self::isCallable(),
            self::isCallable(),
            [42 => $fingerprint],
            self::isCallable(),
            self::isCallable(),
        )->willReturnCallback(static function (array $ids, TriggerType $trigger, mixed $progress, mixed $dispatchedAt, mixed $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $afterObject) use ($report): BulkOrganizeReport {
            self::assertFalse($cancel());
            self::assertTrue($before(42));
            $afterObject($report->objectResults[0]);

            return $report;
        });
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->willReturn('sync-run');
        $dispatcher->expects(self::never())->method('dispatchBulk');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('start')->with('sync-run')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::once())->method('finish')->with('sync-run')->willReturn(OperationRunStatus::Completed);
        $lease = $this->createMock(RunItemLease::class);
        $lease->expects(self::once())->method('start')->with('sync-run', 'object:42')->willReturn(true);
        $lease->expects(self::once())->method('complete')->with(
            'sync-run',
            'object:42',
            OperationRunItemStatus::Completed,
            ['operationCount' => 1],
            null,
        )->willReturn(true);
        $service = $this->service([$object], $organizer, $dispatcher, $runs, $lease);
        $selector = ['assetIds' => [10], 'mode' => 'asset_ids'];
        $preview = $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, true, false, actor: ActorContext::system());

        $result = $service->execute(
            OperationRunKind::Reorganize,
            [42],
            $selector,
            TriggerType::Manual,
            false,
            false,
            $preview->planToken,
            ActorContext::system(),
        );

        self::assertSame('sync-run', $result->runId);
        self::assertSame(OperationRunStatus::Completed, $result->runStatus);
        self::assertSame(1, $result->organized);
        self::assertSame([$report->objectResults[0]], $result->objectResults);
    }

    #[Test]
    public function synchronousApplyFencesCleanupToTheOwnedItemOnFailure(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, mixed $dispatchedAt, mixed $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $afterObject): BulkOrganizeReport {
                $before(42);

                throw new \RuntimeException('boom');
            },
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('sync-run');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('start')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::once())->method('fail')->with('sync-run', 'Reviewed organization failed.');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('token')->willReturnMap([['sync-run', 'object:42', 'tok']]);
        $lease->expects(self::once())->method('complete')
            ->with('sync-run', 'object:42', OperationRunItemStatus::Failed, [], 'Reviewed organization failed.')
            ->willReturn(true);
        $service = $this->service([$object], $organizer, $dispatcher, $runs, $lease);
        $selector = ['assetIds' => [10], 'mode' => 'asset_ids'];
        $preview = $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, true, false, actor: ActorContext::system());

        try {
            $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, false, false, $preview->planToken, ActorContext::system());
            self::fail('Expected ReviewedSelectionException');
        } catch (ReviewedSelectionException $e) {
            self::assertSame(ReviewedSelectionError::ExecutionFailed, $e->error);
        }
    }

    #[Test]
    public function synchronousApplyRaisesOwnershipLostWhenCleanupLosesTheFence(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, mixed $dispatchedAt, mixed $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $afterObject): BulkOrganizeReport {
                $before(42);

                throw new \RuntimeException('boom');
            },
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('sync-run');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('start')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('token')->willReturnMap([['sync-run', 'object:42', 'tok']]);
        $lease->method('complete')->willReturn(false);
        $service = $this->service([$object], $organizer, $dispatcher, $runs, $lease);
        $selector = ['assetIds' => [10], 'mode' => 'asset_ids'];
        $preview = $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, true, false, actor: ActorContext::system());

        try {
            $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, false, false, $preview->planToken, ActorContext::system());
            self::fail('Expected ReviewedSelectionException');
        } catch (ReviewedSelectionException $e) {
            self::assertSame(ReviewedSelectionError::OwnershipLost, $e->error);
        }
    }

    #[Test]
    public function synchronousApplyRaisesOwnershipLostWhenParentFinalizationThrows(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $report = new BulkOrganizeReport([], [new BulkObjectResult(42, BulkObjectStatus::Succeeded, operationCount: 1)]);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, mixed $dispatchedAt, mixed $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $afterObject) use ($report): BulkOrganizeReport {
                $before(42);
                $afterObject($report->objectResults[0]);

                return $report;
            },
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('sync-run');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('start')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('finish')->willThrowException(new \RuntimeException('finalize boom'));
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $lease->method('token')->willReturn(null);
        $service = $this->service([$object], $organizer, $dispatcher, $runs, $lease);
        $selector = ['assetIds' => [10], 'mode' => 'asset_ids'];
        $preview = $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, true, false, actor: ActorContext::system());

        try {
            $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, false, false, $preview->planToken, ActorContext::system());
            self::fail('Expected ReviewedSelectionException');
        } catch (ReviewedSelectionException $e) {
            self::assertSame(ReviewedSelectionError::OwnershipLost, $e->error);
        }
    }

    #[Test]
    public function synchronousApplyRaisesOwnershipLostWhenParentDoesNotSelfFinalize(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $report = new BulkOrganizeReport([], [new BulkObjectResult(42, BulkObjectStatus::Succeeded, operationCount: 1)]);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, mixed $dispatchedAt, mixed $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $afterObject) use ($report): BulkOrganizeReport {
                $before(42);
                $afterObject($report->objectResults[0]);

                return $report;
            },
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('sync-run');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('start')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('finish')->willReturn(OperationRunStatus::Failed);
        $runs->expects(self::never())->method('fail');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(true);
        $lease->method('token')->willReturn(null);
        $service = $this->service([$object], $organizer, $dispatcher, $runs, $lease);
        $selector = ['assetIds' => [10], 'mode' => 'asset_ids'];
        $preview = $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, true, false, actor: ActorContext::system());

        try {
            $service->execute(OperationRunKind::Reorganize, [42], $selector, TriggerType::Manual, false, false, $preview->planToken, ActorContext::system());
            self::fail('Expected ReviewedSelectionException');
        } catch (ReviewedSelectionException $e) {
            self::assertSame(ReviewedSelectionError::OwnershipLost, $e->error);
        }
    }

    #[Test]
    public function missingAndMalformedTokensAreDistinguished(): void
    {
        $object = $this->object(42);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $service = $this->service([$object], $organizer);

        try {
            $service->execute(OperationRunKind::Replay, [42], [], TriggerType::Manual, false, true);
            self::fail('Missing token was accepted.');
        } catch (ReviewedSelectionException $e) {
            self::assertSame(ReviewedSelectionError::MissingPlanToken, $e->error);
        }

        $this->expectReviewedError(ReviewedSelectionError::MalformedPlanToken);
        $service->execute(OperationRunKind::Replay, [42], [], TriggerType::Manual, false, true, 'broken', ActorContext::system());
    }

    /**
     * @param list<AbstractObject> $objects
     *
     * @return ReviewedObjectOperationService&MockObject
     */
    private function service(
        array $objects,
        ?AssetOrganizer $organizer = null,
        ?OrganizeDispatcher $dispatcher = null,
        ?OperationRunStoreInterface $runs = null,
        ?RunItemLease $lease = null,
    ): ReviewedObjectOperationService {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::system());
        $authorization->method('isAllowed')->willReturn(true);
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());
        $this->actorStore = new ActorContextStore($provider);
        $objectMap = [];
        foreach ($objects as $object) {
            $objectMap[(int) $object->getId()] = $object;
        }
        $claims = new InMemoryApplyPlanClaimStore();
        $service = $this->getMockBuilder(ReviewedObjectOperationService::class)
            ->setConstructorArgs([
                $organizer ?? $this->createMock(AssetOrganizer::class),
                $dispatcher ?? $this->createMock(OrganizeDispatcher::class),
                $authorization,
                $this->actorStore,
                $runs ?? $this->createMock(OperationRunStoreInterface::class),
                new ApplyPlanService('test-secret', $claims),
                new OrganizePlanFingerprint(),
                new NullLogger(),
                $lease ?? $this->createMock(RunItemLease::class),
                new ObjectSaveDrain($this->createMock(LoopGuard::class), $this->createMock(OrganizeDispatcher::class), $this->createMock(\Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStoreInterface::class), new NullLogger()),
                ['rules' => ['product-assets']],
            ])
            ->onlyMethods(['loadObject'])
            ->getMock();
        $service->method('loadObject')->willReturnCallback(
            static fn (int $id): ?AbstractObject => $objectMap[$id] ?? null,
        );

        return $service;
    }

    private function object(int $id, int $modifiedAt = 100): Concrete
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn($id);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getRealFullPath')->willReturn('/products/' . $id);
        $object->method('getModificationDate')->willReturn($modifiedAt);
        $object->method('getType')->willReturn('object');
        $object->method('getVersionCount')->willReturn(1);

        return $object;
    }

    private function operation(int $objectId, int $assetId): MoveOperation
    {
        return new MoveOperation(
            $assetId,
            '/incoming/' . $assetId . '.jpg',
            '/organized/' . $assetId . '.jpg',
            $objectId,
            'Product',
            'product-assets',
            OperationStatus::Pending,
            TriggerType::Manual,
        );
    }

    private function expectReviewedError(ReviewedSelectionError $error): void
    {
        $this->expectException(ReviewedSelectionException::class);
        $this->expectExceptionObject(new ReviewedSelectionException($error, $this->errorMessage($error)));
    }

    private function errorMessage(ReviewedSelectionError $error): string
    {
        return match ($error) {
            ReviewedSelectionError::StalePlan => 'The preview plan is stale or already applied. Run a new preview.',
            ReviewedSelectionError::MalformedPlanToken => 'The plan token is malformed or has an invalid signature.',
            default => throw new \LogicException('Unsupported test error.'),
        };
    }
}
