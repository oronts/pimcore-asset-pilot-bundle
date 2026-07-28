<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Maintenance\OrganizeDispatchRelayTask;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(OrganizeDispatchRelayTask::class)]
final class OrganizeDispatchRelayTaskTest extends TestCase
{
    /**
     * @param array{id: string, actorType: string, actorUserId: int|null, trigger: string, targets: list<array{id: int, fingerprint: string|null}>} ...$runs
     *
     * @return OperationRunStoreInterface&MockObject
     */
    private function storeReturning(array ...$runs): OperationRunStoreInterface
    {
        $store = $this->createMock(OperationRunStoreInterface::class);
        $store->method('dueForDispatch')->willReturn($runs);

        return $store;
    }

    /** @param callable(object): void $assert */
    private function assertingBus(callable $assert): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(function (object $message) use ($assert): Envelope {
            $assert($message instanceof Envelope ? $message->getMessage() : $message);

            return $message instanceof Envelope ? $message : new Envelope($message);
        });

        return $bus;
    }

    #[Test]
    public function publishesASingleTargetPendingRunAndMarksItDispatched(): void
    {
        $store = $this->storeReturning(['id' => 'run1', 'actorType' => 'user', 'actorUserId' => 7, 'trigger' => 'object_save', 'targets' => [['id' => 10, 'fingerprint' => 'fp10']]]);
        $store->expects(self::once())->method('markDispatched')->with('run1')->willReturn(true);
        $bus = $this->assertingBus(function (object $message): void {
            self::assertInstanceOf(OrganizeAssetsMessage::class, $message);
            self::assertSame(10, $message->objectId);
            self::assertSame('run1', $message->runId);
            self::assertSame('fp10', $message->expectedFingerprint);
            self::assertSame(7, $message->actorUserId);
        });

        (new OrganizeDispatchRelayTask($bus, $store, new NullLogger(), 50))->execute();
    }

    #[Test]
    public function leavesTheRunPendingAndDoesNotThrowWhenTheBrokerRejectsThePublish(): void
    {
        $store = $this->storeReturning(['id' => 'run1', 'actorType' => 'user', 'actorUserId' => 7, 'trigger' => 'object_save', 'targets' => [['id' => 10, 'fingerprint' => null]]]);
        $store->expects(self::never())->method('markDispatched');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('broker unavailable'));

        // Must not throw out of execute(): the source save already committed and must stay truthful; the run
        // stays pending_dispatch for the next relay pass.
        (new OrganizeDispatchRelayTask($bus, $store, new NullLogger(), 50))->execute();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function failsAnUndispatchableRunInsteadOfLeavingItPendingForever(): void
    {
        $store = $this->storeReturning(['id' => 'run1', 'actorType' => 'user', 'actorUserId' => 7, 'trigger' => 'not-a-real-trigger', 'targets' => [['id' => 10, 'fingerprint' => null]]]);
        $store->expects(self::once())->method('fail')->with('run1', self::stringContains('Undispatchable'));
        $store->expects(self::never())->method('markDispatched');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        (new OrganizeDispatchRelayTask($bus, $store, new NullLogger(), 50))->execute();
    }

    #[Test]
    public function reconstructsABulkMessageForAMultiTargetRun(): void
    {
        $store = $this->storeReturning(['id' => 'run1', 'actorType' => 'system', 'actorUserId' => null, 'trigger' => 'object_save', 'targets' => [['id' => 10, 'fingerprint' => 'a'], ['id' => 20, 'fingerprint' => null]]]);
        $store->method('markDispatched')->willReturn(true);
        $bus = $this->assertingBus(function (object $message): void {
            self::assertInstanceOf(BulkOrganizeMessage::class, $message);
            self::assertSame([10, 20], $message->objectIds);
            self::assertSame([10 => 'a'], $message->expectedFingerprints);
            self::assertNull($message->actorUserId);
        });

        (new OrganizeDispatchRelayTask($bus, $store, new NullLogger(), 50))->execute();
    }
}
