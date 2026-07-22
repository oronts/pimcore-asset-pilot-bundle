<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health;

use Oronts\AssetPilotBundle\Health\WorkerHeartbeatRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

#[CoversClass(WorkerHeartbeatRecorder::class)]
class WorkerHeartbeatRecorderTest extends TestCase
{
    #[Test]
    public function recordsOnlyRequiredTransports(): void
    {
        $cache = new ArrayAdapter();
        $recorder = new class ($cache, 120, new NullLogger()) extends WorkerHeartbeatRecorder {
            protected function now(): int
            {
                return 1000;
            }
        };
        $worker = new Worker(
            [
                'asset_pilot' => $this->createStub(ReceiverInterface::class),
                'unrelated' => $this->createStub(ReceiverInterface::class),
            ],
            $this->createStub(MessageBusInterface::class),
        );

        $recorder->onWorkerRunning(new WorkerRunningEvent($worker, true));

        self::assertSame(1000, $cache->getItem(WorkerHeartbeatRecorder::cacheKey('asset_pilot'))->get());
        self::assertFalse($cache->hasItem(WorkerHeartbeatRecorder::cacheKey('unrelated')));
    }

    #[Test]
    public function cacheFailureDoesNotStopTheWorker(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('cache unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $recorder = new WorkerHeartbeatRecorder($cache, 120, $logger);
        $worker = new Worker(
            ['asset_pilot' => $this->createStub(ReceiverInterface::class)],
            $this->createStub(MessageBusInterface::class),
        );

        $recorder->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }
}
