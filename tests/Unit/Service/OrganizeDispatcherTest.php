<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
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

    private function actors(ActorContext $actor): ActorContextStore
    {
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn($actor);

        return new ActorContextStore($provider);
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

    private function dispatcher(MessageBusInterface $bus, ActorContext $actor): OrganizeDispatcher
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('create')->willReturn(self::RUN_ID);

        return new OrganizeDispatcher($bus, $this->actors($actor), $runs);
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
}
