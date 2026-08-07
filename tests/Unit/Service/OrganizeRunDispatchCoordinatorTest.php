<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\OrganizationRunDispatchException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\QueuedOrganizationRun;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Oronts\AssetPilotBundle\Service\OrganizeRunDispatchCoordinator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OrganizeRunDispatchCoordinator::class)]
final class OrganizeRunDispatchCoordinatorTest extends TestCase
{
    #[Test]
    public function queueOrganizationCreatesTheRunThenDispatchesAndReturnsTheQueuedRun(): void
    {
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('createRun')
            ->with([42], TriggerType::Api, ActorContext::user(7), [42 => 'fp'])->willReturn('run-1');
        $dispatcher->expects(self::once())->method('dispatchObject')
            ->with(42, TriggerType::Api, ActorContext::user(7), 'run-1', 'fp')->willReturn('run-1');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');

        $queued = $this->coordinator($dispatcher, $runs)
            ->queueOrganization(42, TriggerType::Api, ActorContext::user(7), 'fp');

        self::assertEquals(new QueuedOrganizationRun('run-1', 1, 1), $queued);
    }

    #[Test]
    public function queueOrganizationFailsTheRunAndThrowsWhenDispatchThrows(): void
    {
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->method('createRun')->willReturn('run-x');
        $dispatcher->method('dispatchObject')->willThrowException(new \RuntimeException('broker down'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('fail')->with('run-x', 'Organization could not be queued.');

        try {
            $this->coordinator($dispatcher, $runs)->queueOrganization(9, TriggerType::Api, ActorContext::system(), 'fp');
            self::fail('expected OrganizationRunDispatchException');
        } catch (OrganizationRunDispatchException $e) {
            self::assertSame('run-x', $e->runId);
            self::assertSame('Organization could not be queued.', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function runCreationFailurePropagatesRawWithoutWrappingOrCompensation(): void
    {
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->method('createRun')->willThrowException(new \RuntimeException('db down'));
        $dispatcher->expects(self::never())->method('dispatchObject');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');
        $this->coordinator($dispatcher, $runs)->queueOrganization(1, TriggerType::Api, ActorContext::system(), 'fp');
    }

    #[Test]
    public function queueBulkOrganizationBatchesAndIntersectsFingerprintsPerBatch(): void
    {
        $fingerprints = [1 => 'a', 2 => 'b', 3 => 'c'];
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('createRun')
            ->with([1, 2, 3], TriggerType::Api, ActorContext::user(7), $fingerprints)->willReturn('run-b');
        $seen = [];
        $dispatcher->expects(self::exactly(2))->method('dispatchBulk')->willReturnCallback(
            static function (array $batch, TriggerType $t, ActorContext $a, string $runId, array $fps) use (&$seen): string {
                $seen[] = [$batch, $fps];

                return $runId;
            },
        );

        $queued = $this->coordinator($dispatcher, $this->createMock(OperationRunStoreInterface::class))
            ->queueBulkOrganization([1, 2, 3], TriggerType::Api, ActorContext::user(7), $fingerprints, 2);

        self::assertEquals(new QueuedOrganizationRun('run-b', 3, 2), $queued);
        self::assertSame([[[1, 2], [1 => 'a', 2 => 'b']], [[3], [3 => 'c']]], $seen);
    }

    #[Test]
    public function queueBulkFailsOnlyCurrentAndRemainingItemsThenFinishesOnce(): void
    {
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->method('createRun')->willReturn('run-p');
        $calls = 0;
        $dispatcher->method('dispatchBulk')->willReturnCallback(
            static function () use (&$calls): string {
                if (++$calls === 2) {
                    throw new \RuntimeException('broker down');
                }

                return 'run-p';
            },
        );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $failed = [];
        $runs->method('completeItem')->willReturnCallback(
            static function (string $runId, string $itemKey) use (&$failed): bool {
                $failed[] = $itemKey;

                return true;
            },
        );
        $runs->expects(self::once())->method('finish')->with('run-p')->willReturn(OperationRunStatus::Running);
        $runs->expects(self::never())->method('fail');

        try {
            $this->coordinator($dispatcher, $runs)
                ->queueBulkOrganization([1, 2, 3, 4], TriggerType::Api, ActorContext::system(), [], 2);
            self::fail('expected OrganizationRunDispatchException');
        } catch (OrganizationRunDispatchException $e) {
            self::assertSame('run-p', $e->runId);
        }

        self::assertSame(['object:3', 'object:4'], $failed);
    }

    private function coordinator(OrganizeDispatcherInterface $dispatcher, OperationRunStoreInterface $runs): OrganizeRunDispatchCoordinator
    {
        return new OrganizeRunDispatchCoordinator($dispatcher, $runs, new NullLogger());
    }
}
