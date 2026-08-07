<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Audit\AuditRetentionInterface;
use Oronts\AssetPilotBundle\Maintenance\AuditRetentionTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AuditRetentionTask::class)]
class AuditRetentionTaskTest extends TestCase
{
    #[Test]
    public function prunesUsingTheConfiguredRetention(): void
    {
        $audit = $this->createMock(AuditRetentionInterface::class);
        $audit->method('getRetentionDays')->willReturn(90);
        $audit->expects(self::once())->method('cleanup')->with(90)->willReturn(5);

        (new AuditRetentionTask($audit, new NullLogger()))->execute();
    }

    #[Test]
    public function swallowsExceptionsSoTheMaintenanceRunNeverFails(): void
    {
        $this->expectNotToPerformAssertions();

        $audit = $this->createMock(AuditRetentionInterface::class);
        $audit->method('getRetentionDays')->willReturn(90);
        $audit->method('cleanup')->willThrowException(new \RuntimeException('db gone'));

        (new AuditRetentionTask($audit, new NullLogger()))->execute();
    }
}
