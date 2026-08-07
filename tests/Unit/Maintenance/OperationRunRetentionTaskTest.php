<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Maintenance\OperationRunRetentionTask;
use Oronts\AssetPilotBundle\Service\OperationRunRetentionInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OperationRunRetentionTask::class)]
final class OperationRunRetentionTaskTest extends TestCase
{
    #[Test]
    public function prunesAndReconcilesStaleRunsOnMaintenance(): void
    {
        $retention = $this->createMock(OperationRunRetentionInterface::class);
        $retention->expects(self::once())->method('prune')->willReturn(5);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('reconcileExpiredItemLeases')->willReturn(2);
        $runs->expects(self::once())->method('reconcileUnfinalizedRuns')->willReturn(1);

        (new OperationRunRetentionTask($retention, $runs, new NullLogger()))->execute();
    }

    #[Test]
    public function stillReconcilesStaleRunsWhenPruningFails(): void
    {
        $retention = $this->createMock(OperationRunRetentionInterface::class);
        $retention->method('prune')->willThrowException(new \RuntimeException('database unavailable'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('reconcileExpiredItemLeases')->willReturn(0);

        (new OperationRunRetentionTask($retention, $runs, new NullLogger()))->execute();
    }

    #[Test]
    public function doesNotBreakSharedMaintenanceWhenEitherStepFails(): void
    {
        $this->expectNotToPerformAssertions();
        $retention = $this->createMock(OperationRunRetentionInterface::class);
        $retention->method('prune')->willThrowException(new \RuntimeException('database unavailable'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('reconcileExpiredItemLeases')->willThrowException(new \RuntimeException('database unavailable'));

        (new OperationRunRetentionTask($retention, $runs, new NullLogger()))->execute();
    }
}
