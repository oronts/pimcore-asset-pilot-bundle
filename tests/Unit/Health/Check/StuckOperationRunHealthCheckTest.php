<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\StuckOperationRunHealthCheck;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StuckOperationRunHealthCheck::class)]
final class StuckOperationRunHealthCheckTest extends TestCase
{
    #[Test]
    public function okWhenNoRunIsStuckInTheQueue(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('countRunsQueuedLongerThan')->with(86400)->willReturn(0);

        $result = (new StuckOperationRunHealthCheck($runs, 86400))->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame(0, $result->details['stuck_queued_runs']);
    }

    #[Test]
    public function warnsWhenRunsAreStuckPastTheThreshold(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('countRunsQueuedLongerThan')->with(3600)->willReturn(3);

        $result = (new StuckOperationRunHealthCheck($runs, 3600))->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertSame(3, $result->details['stuck_queued_runs']);
        self::assertStringContainsString('3 operation run(s)', $result->message);
    }

    #[Test]
    public function warnsRatherThanBreakingWhenTheBacklogCannotBeInspected(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('countRunsQueuedLongerThan')->willThrowException(new \RuntimeException('database unavailable'));

        $result = (new StuckOperationRunHealthCheck($runs, 86400))->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertStringContainsString('could not be inspected', $result->message);
    }
}
