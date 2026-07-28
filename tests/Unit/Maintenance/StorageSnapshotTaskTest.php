<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Maintenance\StorageSnapshotTask;
use Oronts\AssetPilotBundle\Service\StorageTrendService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(StorageSnapshotTask::class)]
class StorageSnapshotTaskTest extends TestCase
{
    #[Test]
    public function capturesOnEachRun(): void
    {
        $trends = $this->createMock(StorageTrendService::class);
        $trends->expects(self::once())->method('capture')
            ->willReturn([
                'captured' => true,
                'runId' => 7,
                'capturedAt' => '2026-06-19 00:00:00',
                'types' => 2,
                'totalCount' => 3,
                'totalSize' => 150,
                'unknownSizeCount' => 0,
            ]);

        (new StorageSnapshotTask($trends, new NullLogger()))->execute();
    }

    #[Test]
    public function swallowsExceptionsSoTheMaintenanceRunNeverFails(): void
    {
        $this->expectNotToPerformAssertions();

        $trends = $this->createMock(StorageTrendService::class);
        $trends->method('capture')->willThrowException(new \RuntimeException('scan failed'));

        (new StorageSnapshotTask($trends, new NullLogger()))->execute();
    }
}
