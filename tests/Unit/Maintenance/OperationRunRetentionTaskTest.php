<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Maintenance\OperationRunRetentionTask;
use Oronts\AssetPilotBundle\Service\OperationRunRetentionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OperationRunRetentionTask::class)]
final class OperationRunRetentionTaskTest extends TestCase
{
    #[Test]
    public function prunesOneBoundedBatchOnMaintenance(): void
    {
        $retention = $this->createMock(OperationRunRetentionInterface::class);
        $retention->expects(self::once())->method('prune')->willReturn(5);

        (new OperationRunRetentionTask($retention, new NullLogger()))->execute();
    }

    #[Test]
    public function doesNotBreakSharedMaintenanceWhenPruningFails(): void
    {
        $this->expectNotToPerformAssertions();
        $retention = $this->createMock(OperationRunRetentionInterface::class);
        $retention->method('prune')->willThrowException(new \RuntimeException('database unavailable'));

        (new OperationRunRetentionTask($retention, new NullLogger()))->execute();
    }
}
