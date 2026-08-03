<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\AutomaticOrganizeIntentBinding;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStoreInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(OrganizeDispatcher::class)]
class OrganizeDispatcherTest extends TestCase
{
    private const string RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function authorization(ActorContext $actor): ElementAuthorizationInterface
    {
        $authorization = $this->createMock(ElementAuthorizationInterface::class);
        $authorization->method('currentActor')->willReturn($actor);

        return $authorization;
    }

    private function captureDispatch(?Envelope &$captured): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (Envelope $envelope) use (&$captured): Envelope {
                $captured = $envelope;

                return $envelope;
            },
        );

        return $bus;
    }

    private function dispatcher(
        MessageBusInterface $bus,
        ActorContext $actor,
        ?OperationRunStoreInterface $runs = null,
        ?AutomaticOrganizeIntentStoreInterface $intents = null,
    ): OrganizeDispatcher {
        if ($runs === null) {
            $runs = $this->createMock(OperationRunStoreInterface::class);
            $runs->method('create')->willReturn(self::RUN_ID);
        }

        return new OrganizeDispatcher($bus, $this->authorization($actor), $runs, $intents ?? $this->createMock(AutomaticOrganizeIntentStoreInterface::class));
    }

    #[Test]
    public function deferObjectRecordsOnePendingRunForTheWinningSave(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('create')->willReturnArgument(6);
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->method('bindOrCoalesce')->willReturnCallback(
            static fn (int $objectId, string $candidate): AutomaticOrganizeIntentBinding => new AutomaticOrganizeIntentBinding($candidate, true),
        );

        $runId = $this->dispatcher($this->createMock(MessageBusInterface::class), ActorContext::system(), $runs, $intents)
            ->deferObject(42, TriggerType::ObjectSave);

        self::assertNotSame('', $runId, 'the winning save owns a fresh pending run');
    }

    #[Test]
    public function deferObjectCoalescesASecondSaveWithoutRecordingASecondRun(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('create');
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->method('bindOrCoalesce')->willReturn(new AutomaticOrganizeIntentBinding('existing-run', false));

        self::assertSame(
            'existing-run',
            $this->dispatcher($this->createMock(MessageBusInterface::class), ActorContext::system(), $runs, $intents)->deferObject(42, TriggerType::ObjectSave),
        );
    }

    #[Test]
    public function dispatchObjectQueuesTheMessageWithItsUniqueRun(): void
    {
        $captured = null;
        $runId = $this->dispatcher($this->captureDispatch($captured), ActorContext::user(7))->dispatchObject(
            42,
            TriggerType::Api,
            expectedFingerprint: 'fingerprint-42',
        );

        self::assertInstanceOf(Envelope::class, $captured);
        $message = $captured->getMessage();
        self::assertInstanceOf(OrganizeAssetsMessage::class, $message);
        self::assertSame(42, $message->objectId);
        self::assertSame(TriggerType::Api, $message->triggerType);
        self::assertSame(7, $message->actorUserId);
        self::assertSame(self::RUN_ID, $message->runId);
        self::assertSame('fingerprint-42', $message->expectedFingerprint);
        self::assertSame(self::RUN_ID, $runId);

        self::assertSame([], $captured->all());
    }

    #[Test]
    public function dispatchBulkQueuesTheBatchWithItsUniqueRun(): void
    {
        $captured = null;
        $this->dispatcher($this->captureDispatch($captured), ActorContext::system())->dispatchBulk(
            [1, 2, 3],
            TriggerType::BulkOperation,
            expectedFingerprints: [1 => 'fingerprint-1', 2 => 'fingerprint-2', 3 => 'fingerprint-3'],
        );

        self::assertInstanceOf(Envelope::class, $captured);
        $message = $captured->getMessage();
        self::assertInstanceOf(BulkOrganizeMessage::class, $message);
        self::assertSame([1, 2, 3], $message->objectIds);
        self::assertSame(TriggerType::BulkOperation, $message->triggerType);
        self::assertSame(self::RUN_ID, $message->runId);
        self::assertSame(
            [1 => 'fingerprint-1', 2 => 'fingerprint-2', 3 => 'fingerprint-3'],
            $message->expectedFingerprints,
        );

        self::assertSame([], $captured->all());
    }

    #[Test]
    public function dispatchBulkSortsIdsForDeterministicExecution(): void
    {
        $captured = null;
        $this->dispatcher($this->captureDispatch($captured), ActorContext::system())->dispatchBulk([3, 1, 2], TriggerType::BulkOperation);

        self::assertInstanceOf(Envelope::class, $captured);
        self::assertSame([1, 2, 3], $captured->getMessage()->objectIds);
    }

    #[Test]
    public function createRunPassesTheTypedKindToTheStore(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $capturedKind = null;
        $runs->expects(self::once())->method('create')->willReturnCallback(
            static function (OperationRunKind $kind) use (&$capturedKind): string {
                $capturedKind = $kind;

                return self::RUN_ID;
            },
        );

        $runId = (new OrganizeDispatcher($bus, $this->authorization(ActorContext::system()), $runs, $this->createMock(AutomaticOrganizeIntentStoreInterface::class)))->createRun(
            [42],
            TriggerType::Manual,
            kind: OperationRunKind::Reorganize,
        );

        self::assertSame(OperationRunKind::Reorganize, $capturedKind);
        self::assertSame(self::RUN_ID, $runId);
    }

    #[Test]
    public function dispatchObjectFailsANewRunWhenTransportDispatchFails(): void
    {
        $failure = new \RuntimeException('Transport unavailable.');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException($failure);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('create')->willReturn(self::RUN_ID);
        $runs->expects(self::once())
            ->method('fail')
            ->with(self::RUN_ID, 'The organize operation could not be dispatched.');

        try {
            $this->dispatcher($bus, ActorContext::user(7), $runs)->dispatchObject(42, TriggerType::Api);
            self::fail('Expected the transport failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    #[Test]
    public function dispatchBulkFailsANewRunWhenTransportDispatchFails(): void
    {
        $failure = new \RuntimeException('Transport unavailable.');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException($failure);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('create')->willReturn(self::RUN_ID);
        $runs->expects(self::once())
            ->method('fail')
            ->with(self::RUN_ID, 'The organize operation could not be dispatched.');

        try {
            $this->dispatcher($bus, ActorContext::system(), $runs)->dispatchBulk([42, 43], TriggerType::BulkOperation);
            self::fail('Expected the transport failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    #[Test]
    public function dispatchDoesNotFailACallerOwnedRunWhenTransportDispatchFails(): void
    {
        $failure = new \RuntimeException('Transport unavailable.');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException($failure);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('create');
        $runs->expects(self::never())->method('fail');

        $this->expectExceptionObject($failure);
        $this->dispatcher($bus, ActorContext::system(), $runs)->dispatchObject(
            42,
            TriggerType::Api,
            runId: self::RUN_ID,
        );
    }
}
