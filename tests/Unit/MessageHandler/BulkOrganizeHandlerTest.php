<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\MessageHandler;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\MessageHandler\BulkOrganizeHandler;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStoreInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrain;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrainInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[CoversClass(BulkOrganizeHandler::class)]
class BulkOrganizeHandlerTest extends TestCase
{
    private function handler(
        AssetOrganizer $organizer,
        ?OrganizeDispatcher $dispatcher = null,
        ?LoopGuard $loopGuard = null,
        ?OperationRunStoreInterface $runs = null,
        array $objects = [],
    ): BulkOrganizeHandler {
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());
        $actors = new ActorContextStore($provider);
        if ($loopGuard === null) {
            $loopGuard = $this->createMock(LoopGuard::class);
            $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        }

        $dispatcher ??= $this->createMock(OrganizeDispatcher::class);
        $drain = new ObjectSaveDrain($loopGuard, $dispatcher, $this->createMock(AutomaticOrganizeIntentStoreInterface::class), $this->createMock(Connection::class), new NullLogger());

        return new class (
            $organizer,
            $dispatcher,
            $actors,
            $loopGuard,
            new NullLogger(),
            $runs ?? $this->createMock(OperationRunStoreInterface::class),
            new OrganizePlanFingerprint(),
            $drain,
            $objects,
        ) extends BulkOrganizeHandler {
            /** @param array<int, AbstractObject> $objects */
            public function __construct(
                AssetOrganizer $organizer,
                OrganizeDispatcher $dispatcher,
                ActorContextStore $actors,
                LoopGuard $loopGuard,
                NullLogger $logger,
                OperationRunStoreInterface $runs,
                OrganizePlanFingerprint $fingerprints,
                ObjectSaveDrainInterface $drain,
                private readonly array $objects,
            ) {
                parent::__construct($organizer, $dispatcher, $actors, $loopGuard, $logger, $runs, $fingerprints, $drain);
            }

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return $this->objects[$objectId] ?? null;
            }
        };
    }

    #[Test]
    public function callsOrganizeBulkWithCorrectArgs(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())
            ->method('organizeBulkDetailed')
            ->with([1, 2, 3], TriggerType::BulkOperation, null, 0, self::isCallable(), null, null, [], null, null)
            ->willReturn(new BulkOrganizeReport([], []));

        $handler = $this->handler($organizer);
        $message = new BulkOrganizeMessage(
            objectIds: [1, 2, 3],
            triggerType: TriggerType::BulkOperation,
            actorType: ActorType::System,
        );

        $handler($message);
    }

    #[Test]
    public function rethrowsExceptionFromOrganizer(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulkDetailed')->willThrowException(new \RuntimeException('bulk failed'));

        $handler = $this->handler($organizer);
        $message = new BulkOrganizeMessage(
            objectIds: [1],
            triggerType: TriggerType::BulkOperation,
            actorType: ActorType::System,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bulk failed');

        $handler($message);
    }

    #[Test]
    public function handlerIsCallable(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $handler = $this->handler($organizer);

        self::assertIsCallable($handler);
    }

    #[Test]
    public function handlesEmptyObjectIds(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())
            ->method('organizeBulkDetailed')
            ->with([], TriggerType::Manual, null, 0, self::isCallable(), null, null, [], null, null)
            ->willReturn(new BulkOrganizeReport([], []));

        $handler = $this->handler($organizer);
        $message = new BulkOrganizeMessage(
            objectIds: [],
            triggerType: TriggerType::Manual,
            actorType: ActorType::System,
        );

        $handler($message);
    }

    #[Test]
    public function requeuesObjectsSavedWhileTheBulkJobWasProcessing(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulkDetailed')->willReturn(new BulkOrganizeReport([], []));
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(
            2,
            TriggerType::BulkOperation,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
        );
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::exactly(3))
            ->method('isObjectDirty')
            ->willReturnMap([[1, false], [2, true], [3, false]]);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(2);

        ($this->handler($organizer, $dispatcher, $loopGuard))(
            new BulkOrganizeMessage(
                objectIds: [1, 2, 3],
                triggerType: TriggerType::BulkOperation,
                actorType: ActorType::System,
            ),
        );
    }

    #[Test]
    public function drainsAFingerprintedObjectSavedWhileTheBulkJobWasProcessing(): void
    {
        // A save that coalesced into an immutable-plan bulk run still marks the object dirty; the tracked drain
        // must re-dispatch it, not skip it because the object carried an expected fingerprint.
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(2);
        $expected = (new OrganizePlanFingerprint())->forOperations($object, []);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([]);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, int $dispatchedAt, ?callable $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $after): BulkOrganizeReport {
                $before(2);
                $after(new BulkObjectResult(2, BulkObjectStatus::Succeeded, operationCount: 1));

                return new BulkOrganizeReport([], []);
            },
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(
            2,
            TriggerType::BulkOperation,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
        );
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        $loopGuard->method('beginOperationRunItemLease')->willReturn('token-2');
        $loopGuard->method('isObjectDirty')->with(2)->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(2);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->method('completeItem')->willReturn(true);
        $runs->method('finish')->willReturn(OperationRunStatus::Completed);

        ($this->handler($organizer, $dispatcher, $loopGuard, $runs, [2 => $object]))(
            new BulkOrganizeMessage(
                objectIds: [2],
                triggerType: TriggerType::BulkOperation,
                actorType: ActorType::System,
                runId: 'run-1',
                expectedFingerprints: [2 => $expected],
            ),
        );
    }

    #[Test]
    public function drainsAFingerprintMismatchedObjectThatAConcurrentSaveMarkedDirty(): void
    {
        // The reviewed plan changed after preview (often a coalesced save marked the object dirty); the skip path
        // must still drain it so its new state is organized rather than lost.
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(2);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([]);
        $organizer->expects(self::never())->method('organizeBulkDetailed');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(2, TriggerType::BulkOperation, self::anything());
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        $loopGuard->method('isObjectDirty')->with(2)->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(2);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('resume')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('completeItem')->willReturn(true);
        $runs->method('finish')->willReturn(OperationRunStatus::Completed);

        ($this->handler($organizer, $dispatcher, $loopGuard, $runs, [2 => $object]))(
            new BulkOrganizeMessage(
                objectIds: [2],
                triggerType: TriggerType::BulkOperation,
                actorType: ActorType::System,
                runId: 'run-1',
                expectedFingerprints: [2 => 'stale-fingerprint-that-will-not-match'],
            ),
        );
    }

    #[Test]
    public function recordsPerObjectProgressForTrackedBatches(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('organizeBulkDetailed')
            ->willReturnCallback(static function (array $ids, TriggerType $trigger, mixed $progress, int $dispatchedAt, callable $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $after): BulkOrganizeReport {
                self::assertFalse($cancel());
                self::assertTrue($before(42));
                $heartbeat(42);
                $result = new BulkObjectResult(42, BulkObjectStatus::Succeeded, operationCount: 2);
                $after($result);

                return new BulkOrganizeReport([], [$result]);
            });
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('resume')->with('run-1')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::once())->method('resumeItem')->with('run-1', 'object:42')->willReturn(true);
        $runs->expects(self::once())->method('completeItem')->with(
            'run-1',
            'object:42',
            OperationRunItemStatus::Completed,
            ['operationCount' => 2],
            null,
        )->willReturn(true);
        $runs->expects(self::once())->method('finish')->with('run-1')->willReturn(OperationRunStatus::Completed);

        ($this->handler($organizer, runs: $runs))(
            new BulkOrganizeMessage([42], TriggerType::Api, actorType: ActorType::System, runId: 'run-1'),
        );
    }

    #[Test]
    public function releasesTheItemWhenResumeItemThrowsSoTheOrphanedTokenCannotHangTheRun(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, int $dispatchedAt, callable $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $after): BulkOrganizeReport {
                // beforeObject runs outside the per-object try in the real organizer, so a resumeItem throw
                // here propagates just like production; beginItem must have released the item on its way out.
                $before(42);

                return new BulkOrganizeReport([], []);
            },
        );
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        $loopGuard->method('beginOperationRunItemLease')->willReturn('token-42');
        $loopGuard->expects(self::once())->method('releaseOperationRunItem')->with('run-1', 'object:42');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willThrowException(new \RuntimeException('MySQL server has gone away'));
        // beginItem released the item, clearing the token, so failBatch can fail the still-queued item unfenced.
        $runs->method('completeItem')->with('run-1', 'object:42', OperationRunItemStatus::Failed, [], 'Bulk organization failed.', null)->willReturn(true);
        $runs->method('finish')->willReturn(OperationRunStatus::Failed);

        ($this->handler($organizer, loopGuard: $loopGuard, runs: $runs))(
            new BulkOrganizeMessage([42], TriggerType::Api, actorType: ActorType::System, runId: 'run-1'),
        );
    }

    #[Test]
    public function retryableInfrastructureFailureLeavesBatchItemsResumable(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulkDetailed')->willThrowException(new LockStorageException('cache unavailable'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->expects(self::never())->method('completeItem');
        $runs->expects(self::never())->method('finish');

        $this->expectException(LockStorageException::class);

        ($this->handler($organizer, runs: $runs))(
            new BulkOrganizeMessage([42], TriggerType::Api, actorType: ActorType::System, runId: 'run-1'),
        );
    }

    #[Test]
    public function completedItemsStayTerminalWhenALaterItemNeedsRedelivery(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, int $dispatchedAt, callable $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $after): never {
                self::assertTrue($before(41));
                $heartbeat(41);
                $after(new BulkObjectResult(41, BulkObjectStatus::Succeeded, operationCount: 1));
                self::assertTrue($before(42));

                throw new LockStorageException('cache unavailable');
            },
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->expects(self::exactly(2))->method('resumeItem')->willReturn(true);
        $runs->expects(self::once())->method('completeItem')->with(
            'run-1',
            'object:41',
            OperationRunItemStatus::Completed,
            ['operationCount' => 1],
            null,
        )->willReturn(true);
        $runs->expects(self::never())->method('finish');

        $this->expectException(LockStorageException::class);

        ($this->handler($organizer, runs: $runs))(
            new BulkOrganizeMessage([41, 42], TriggerType::Api, actorType: ActorType::System, runId: 'run-1'),
        );
    }

    #[Test]
    public function concurrentItemDeliveryIsRetriedBeforeItemStateChanges(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(false);
        $loopGuard->expects(self::never())->method('releaseOperationRunItem');
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, int $dispatchedAt, callable $stale, callable $cancel, callable $before): BulkOrganizeReport {
                self::assertFalse($before(42));

                return new BulkOrganizeReport([], []);
            },
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::once())->method('resume')->with('run-1')->willReturn(true);
        $runs->expects(self::never())->method('resumeItem');
        $runs->expects(self::never())->method('completeItem');
        $runs->expects(self::never())->method('finish');

        $this->expectException(RecoverableMessageHandlingException::class);

        ($this->handler($organizer, loopGuard: $loopGuard, runs: $runs))(
            new BulkOrganizeMessage([42], TriggerType::Api, actorType: ActorType::System, runId: 'run-1'),
        );
    }

    #[Test]
    public function aLockConflictRetriesWithoutFinalizingOrClobberingTheConcurrentlyOwnedItem(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturnCallback(
            static fn (string $runId, string $itemKey): bool => $itemKey === 'object:41',
        );
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, int $dispatchedAt, callable $stale, callable $cancel, callable $before, array $fingerprints, callable $heartbeat, callable $after): BulkOrganizeReport {
                self::assertTrue($before(41));
                $after(new BulkObjectResult(41, BulkObjectStatus::Succeeded, operationCount: 1));
                self::assertFalse($before(42));

                return new BulkOrganizeReport([], [new BulkObjectResult(41, BulkObjectStatus::Succeeded, operationCount: 1)]);
            },
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->expects(self::once())->method('completeItem')->with(
            'run-1',
            'object:41',
            OperationRunItemStatus::Completed,
            self::anything(),
            self::anything(),
            self::anything(),
        )->willReturn(true);
        $runs->expects(self::never())->method('finish');

        $this->expectException(RecoverableMessageHandlingException::class);

        ($this->handler($organizer, loopGuard: $loopGuard, runs: $runs))(
            new BulkOrganizeMessage([41, 42], TriggerType::Api, actorType: ActorType::System, runId: 'run-1'),
        );
    }

    #[Test]
    public function aThrowAfterAnEarlierLockConflictRetriesInsteadOfClobbering(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturnCallback(
            static fn (string $runId, string $itemKey): bool => $itemKey !== 'object:41',
        );
        $loopGuard->method('beginOperationRunItemLease')->willReturn('token-42');
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulkDetailed')->willReturnCallback(
            static function (array $ids, TriggerType $trigger, mixed $progress, int $dispatchedAt, callable $stale, callable $cancel, callable $before): BulkOrganizeReport {
                self::assertFalse($before(41)); // the first item's lock is held by a concurrent worker
                $before(42);                    // a later item's resumeItem throws, so organizeBatch throws

                return new BulkOrganizeReport([], []);
            },
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willThrowException(new \RuntimeException('boom'));
        $runs->expects(self::never())->method('completeItem');
        $runs->expects(self::never())->method('finish');

        $this->expectException(RecoverableMessageHandlingException::class);

        ($this->handler($organizer, loopGuard: $loopGuard, runs: $runs))(
            new BulkOrganizeMessage([41, 42], TriggerType::Api, actorType: ActorType::System, runId: 'run-1'),
        );
    }

    #[Test]
    public function plannedBatchCarriesFingerprintsIntoTheLockedOrganizerPass(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(42);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getRealFullPath')->willReturn('/products/42');
        $object->method('getModificationDate')->willReturn(100);
        $object->method('getType')->willReturn('object');
        $object->method('getVersionCount')->willReturn(1);
        $operation = new MoveOperation(
            10,
            '/incoming/a.jpg',
            '/organized/a.jpg',
            42,
            'Product',
            'product-assets',
            OperationStatus::Pending,
            TriggerType::Api,
        );
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('dryRun')->with($object, TriggerType::Api)->willReturn([$operation]);
        $organizer->expects(self::once())->method('organizeBulkDetailed')->with(
            [42],
            TriggerType::Api,
            null,
            0,
            null,
            null,
            null,
            [42 => $fingerprint],
            null,
            null,
        )->willReturn(new BulkOrganizeReport([], []));

        ($this->handler($organizer, objects: [42 => $object]))(
            new BulkOrganizeMessage(
                [42],
                TriggerType::Api,
                actorType: ActorType::System,
                expectedFingerprints: [42 => $fingerprint],
            ),
        );
    }
}
