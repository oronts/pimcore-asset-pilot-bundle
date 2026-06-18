<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Service\MetricsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsService::class)]
class MetricsServiceTest extends TestCase
{
    /**
     * @param array<string, mixed>                                                 $stats
     * @param array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null} $duration
     */
    private function service(array $stats, array $duration): MetricsService
    {
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->method('getStats')->willReturn($stats);
        $audit->method('getDurationStats')->willReturn($duration);

        return new MetricsService($audit);
    }

    #[Test]
    public function aggregatesStatusCountsTotalAndFailureRate(): void
    {
        $metrics = $this->service(
            ['completed' => 10, 'failed' => 2, 'skipped' => 8, 'by_class' => ['Product' => 20]],
            ['count' => 10, 'avgMs' => 42.5, 'minMs' => 5, 'maxMs' => 300],
        )->collect();

        self::assertSame(['completed' => 10, 'failed' => 2, 'skipped' => 8], $metrics['operations']);
        self::assertSame(20, $metrics['total']);
        self::assertSame(0.1, $metrics['failureRate']);
        self::assertSame(42.5, $metrics['durationMs']['avgMs']);
        self::assertArrayNotHasKey('by_class', $metrics['operations']);
    }

    #[Test]
    public function failureRateIsZeroWhenThereAreNoOperations(): void
    {
        $metrics = $this->service(
            ['by_class' => []],
            ['count' => 0, 'avgMs' => null, 'minMs' => null, 'maxMs' => null],
        )->collect();

        self::assertSame(0, $metrics['total']);
        self::assertSame(0.0, $metrics['failureRate']);
        self::assertSame([], $metrics['operations']);
    }
}
