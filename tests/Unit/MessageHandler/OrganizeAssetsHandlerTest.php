<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\MessageHandler;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\RetryableDispatchException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\MessageHandler\OrganizeAssetsHandler;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
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

#[CoversClass(OrganizeAssetsHandler::class)]
class OrganizeAssetsHandlerTest extends TestCase
{
    private function handler(
        AbstractObject $object,
        AssetOrganizer $organizer,
        OrganizeDispatcher $dispatcher,
        ElementAuthorization $authorization,
        ?AbstractObject $reloadedObject = null,
        ?LoopGuard $loopGuard = null,
        ?OperationRunStoreInterface $runs = null,
    ): OrganizeAssetsHandler {
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());
        $actors = new ActorContextStore($provider);
        if ($loopGuard === null) {
            $loopGuard = $this->createMock(LoopGuard::class);
            $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        }

        return new class ($organizer, $dispatcher, $authorization, $actors, $loopGuard, new NullLogger(), $runs ?? $this->createMock(OperationRunStoreInterface::class), new OrganizePlanFingerprint(), $object, $reloadedObject ?? $object) extends OrganizeAssetsHandler {
            public function __construct(AssetOrganizer $organizer, OrganizeDispatcher $dispatcher, ElementAuthorization $authorization, ActorContextStore $actors, LoopGuard $loopGuard, NullLogger $logger, OperationRunStoreInterface $runs, OrganizePlanFingerprint $fingerprints, private readonly AbstractObject $object, private readonly AbstractObject $reloadedObject)
            {
                parent::__construct($organizer, $dispatcher, $authorization, $actors, $loopGuard, $logger, $runs, $fingerprints);
            }

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return $this->object;
            }

            protected function reloadObject(int $objectId): ?AbstractObject
            {
                return $this->reloadedObject;
            }
        };
    }

    #[Test]
    public function requeuesLatestStateWhenReceivedMessageIsStale(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getModificationDate')->willReturn(200);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organize');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(
            42,
            TriggerType::ObjectSave,
            self::callback(static fn (ActorContext $actor): bool => $actor->userId === 7),
        );
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);

        ($this->handler($object, $organizer, $dispatcher, $authorization))(
            new OrganizeAssetsMessage(42, TriggerType::ObjectSave, 100, ActorType::User, 7),
        );
    }

    #[Test]
    public function staleReplacementDispatchFailureLeavesTheRunItemResumable(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getModificationDate')->willReturn(200);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organizeWithHeartbeat');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())
            ->method('dispatchObject')
            ->with(42, TriggerType::ObjectSave, ActorContext::user(7))
            ->willThrowException(new \RuntimeException('broker unavailable'));
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->expects(self::never())->method('completeItem');
        $runs->expects(self::never())->method('finish');
        $runs->expects(self::never())->method('fail');

        $this->expectException(RetryableDispatchException::class);

        ($this->handler($object, $organizer, $dispatcher, $authorization, runs: $runs))(
            new OrganizeAssetsMessage(42, TriggerType::ObjectSave, 100, ActorType::User, 7, 'run-1'),
        );
    }


    #[Test]
    public function dropsMessageWhenActorLostWorkspacePermission(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organize');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);

        ($this->handler($object, $organizer, $this->createMock(OrganizeDispatcher::class), $authorization))(
            new OrganizeAssetsMessage(42, TriggerType::Api, 100, ActorType::User, 7),
        );
    }

    #[Test]
    public function requeuesWhenObjectChangesWhileOrganizationIsRunning(): void
    {
        $loaded = $this->createMock(Concrete::class);
        $loaded->method('getModificationDate')->willReturn(100);
        $reloaded = $this->createMock(Concrete::class);
        $reloaded->method('getModificationDate')->willReturn(101);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('organize')->with($loaded, TriggerType::ObjectSave)->willReturn([]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(
            42,
            TriggerType::ObjectSave,
            self::callback(static fn (ActorContext $actor): bool => $actor->userId === 7),
        );
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);

        ($this->handler($loaded, $organizer, $dispatcher, $authorization, $reloaded))(
            new OrganizeAssetsMessage(42, TriggerType::ObjectSave, 100, ActorType::User, 7),
        );
    }

    #[Test]
    public function requeuesWhenObjectIsSavedWithinTheSameSecond(): void
    {
        $loaded = $this->createMock(Concrete::class);
        $loaded->method('getModificationDate')->willReturn(100);
        $reloaded = $this->createMock(Concrete::class);
        $reloaded->method('getModificationDate')->willReturn(100);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('organize')->willReturn([]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(
            42,
            TriggerType::ObjectSave,
            self::callback(static fn (ActorContext $actor): bool => $actor->userId === 7),
        );
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('clearObjectDispatched')->with(42);
        $loopGuard->expects(self::once())->method('isObjectDirty')->with(42)->willReturn(true);
        $loopGuard->expects(self::once())->method('markObjectDirty')->with(42);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(42);

        ($this->handler($loaded, $organizer, $dispatcher, $authorization, $reloaded, $loopGuard))(
            new OrganizeAssetsMessage(42, TriggerType::ObjectSave, 100, ActorType::User, 7),
        );
    }

    #[Test]
    public function recordsACompletedRunItemForTrackedMessages(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organize')->willReturn([]);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('resume')->with('run-1')->willReturn(true);
        $runs->expects(self::once())->method('resumeItem')->with('run-1', 'object:42')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::once())->method('completeItem')->with(
            'run-1',
            'object:42',
            OperationRunItemStatus::Skipped,
            ['operationCount' => 0],
            null,
        )->willReturn(true);
        $runs->expects(self::once())->method('finish')->with('run-1')->willReturn(OperationRunStatus::Completed);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDispatched')->with(42);

        ($this->handler($object, $organizer, $this->createMock(OrganizeDispatcher::class), $authorization, runs: $runs, loopGuard: $loopGuard))(
            new OrganizeAssetsMessage(42, TriggerType::Api, actorType: ActorType::User, actorUserId: 7, runId: 'run-1'),
        );
    }

    #[Test]
    public function ignoresDeliveryWhenItsRunItemIsAlreadyTerminal(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organize');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->expects(self::never())->method('isAllowed');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('resume')->with('run-1')->willReturn(false);
        $runs->expects(self::never())->method('resumeItem');
        $runs->expects(self::once())->method('isCancellationRequested')->willReturn(false);

        ($this->handler($object, $organizer, $this->createMock(OrganizeDispatcher::class), $authorization, runs: $runs))(
            new OrganizeAssetsMessage(42, TriggerType::Api, actorType: ActorType::User, actorUserId: 7, runId: 'run-1'),
        );
    }
    #[Test]
    public function latestStateDispatchFailureKeepsDirtyTrackedItemResumable(): void
    {
        $loaded = $this->createMock(Concrete::class);
        $loaded->method('getModificationDate')->willReturn(100);
        $reloaded = $this->createMock(Concrete::class);
        $reloaded->method('getModificationDate')->willReturn(101);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())
            ->method('organizeWithHeartbeat')
            ->willReturn([]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())
            ->method('dispatchObject')
            ->willThrowException(new \RuntimeException('broker unavailable'));
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        $loopGuard->expects(self::once())->method('isObjectDirty')->with(42)->willReturn(false);
        $loopGuard->expects(self::once())->method('markObjectDirty')->with(42);
        $loopGuard->expects(self::never())->method('clearObjectDirty');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->expects(self::never())->method('completeItem');
        $runs->expects(self::never())->method('finish');
        $runs->expects(self::never())->method('fail');

        $this->expectException(RetryableDispatchException::class);

        ($this->handler($loaded, $organizer, $dispatcher, $authorization, $reloaded, $loopGuard, $runs))(
            new OrganizeAssetsMessage(42, TriggerType::ObjectSave, 100, ActorType::User, 7, 'run-1'),
        );
    }


    #[Test]
    public function drainsACoalescedSaveWhenTheTrackedOrganizeFailsNonRetryably(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getModificationDate')->willReturn(100);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new \RuntimeException('rule action threw'));
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDispatched')->with(42);
        $loopGuard->expects(self::once())->method('isObjectDirty')->with(42)->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(42);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(
            42,
            TriggerType::ObjectSave,
            self::callback(static fn (ActorContext $actor): bool => $actor->userId === 7),
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->expects(self::once())->method('completeItem')->willReturn(true);
        $runs->expects(self::once())->method('fail')->with('run-1', self::anything());

        ($this->handler($object, $organizer, $dispatcher, $authorization, loopGuard: $loopGuard, runs: $runs))(
            new OrganizeAssetsMessage(42, TriggerType::ObjectSave, 100, ActorType::User, 7, 'run-1'),
        );
    }

    #[Test]
    public function drainsACoalescedSaveWhenTheTrackedRunTerminatesWithoutOrganizing(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getModificationDate')->willReturn(100);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organizeWithHeartbeat');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(
            42,
            TriggerType::ObjectSave,
            self::callback(static fn (ActorContext $actor): bool => $actor->userId === 7),
        );
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(true);
        $loopGuard->method('isObjectDirty')->with(42)->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(42);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->expects(self::once())->method('completeItem')->willReturn(true);
        $runs->method('finish')->willReturn(OperationRunStatus::Failed);

        ($this->handler($object, $organizer, $dispatcher, $authorization, loopGuard: $loopGuard, runs: $runs))(
            new OrganizeAssetsMessage(42, TriggerType::ObjectSave, 100, ActorType::User, 7, 'run-1'),
        );
    }

    #[Test]
    public function retryableInfrastructureFailureLeavesTheRunItemResumable(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new LockStorageException('cache unavailable'));
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->expects(self::never())->method('completeItem');
        $runs->expects(self::never())->method('fail');

        $this->expectException(LockStorageException::class);

        ($this->handler($object, $organizer, $this->createMock(OrganizeDispatcher::class), $authorization, runs: $runs))(
            new OrganizeAssetsMessage(42, TriggerType::Api, actorType: ActorType::User, actorUserId: 7, runId: 'run-1'),
        );
    }

    #[Test]
    public function concurrentDeliveryIsRetriedBeforeRunStateChanges(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireOperationRunItem')->willReturn(false);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organizeWithHeartbeat');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('resume');

        $this->expectException(RecoverableMessageHandlingException::class);

        ($this->handler(
            $this->createMock(AbstractObject::class),
            $organizer,
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(ElementAuthorization::class),
            loopGuard: $loopGuard,
            runs: $runs,
        ))(new OrganizeAssetsMessage(42, TriggerType::Api, actorType: ActorType::System, runId: 'run-1'));
    }

    #[Test]
    public function plannedMessageBecomesTerminalWhenTheLockedRevalidationIsStale(): void
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
        $organizer->expects(self::once())->method('organizeWithHeartbeat')->with(
            $object,
            TriggerType::Api,
            self::isCallable(),
            null,
            $fingerprint,
        )->willThrowException(new StaleApplyPlanException(42));
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchObject');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('resume')->willReturn(true);
        $runs->method('resumeItem')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::once())->method('completeItem')->with(
            'run-1',
            'object:42',
            OperationRunItemStatus::Skipped,
            [],
            'Object changed after preview; the immutable plan was not applied.',
        )->willReturn(true);
        $runs->expects(self::once())->method('finish')->with('run-1')->willReturn(OperationRunStatus::Partial);

        ($this->handler($object, $organizer, $dispatcher, $authorization, runs: $runs))(
            new OrganizeAssetsMessage(
                42,
                TriggerType::Api,
                actorType: ActorType::User,
                actorUserId: 7,
                runId: 'run-1',
                expectedFingerprint: $fingerprint,
            ),
        );
    }
}
