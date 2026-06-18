<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\PrometheusFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrometheusFormatter::class)]
class PrometheusFormatterTest extends TestCase
{
    #[Test]
    public function rendersCountersGaugesAndDurationAggregates(): void
    {
        $output = (new PrometheusFormatter())->format([
            'operations' => ['completed' => 120, 'failed' => 4],
            'total' => 124,
            'failureRate' => 0.0323,
            'durationMs' => ['count' => 120, 'avgMs' => 45.5, 'minMs' => 10, 'maxMs' => 800],
        ]);

        self::assertStringContainsString('# TYPE asset_pilot_operations gauge', $output);
        self::assertStringContainsString('asset_pilot_operations{status="completed"} 120', $output);
        self::assertStringContainsString('asset_pilot_operations{status="failed"} 4', $output);
        self::assertStringContainsString('asset_pilot_operations_count 124', $output);
        self::assertStringContainsString('asset_pilot_failure_rate 0.0323', $output);
        self::assertStringContainsString('asset_pilot_operation_duration_ms{aggregate="avg"} 45.5', $output);
        self::assertStringContainsString('asset_pilot_operation_duration_ms{aggregate="min"} 10', $output);
        self::assertStringContainsString('asset_pilot_operation_duration_count 120', $output);
        self::assertStringEndsWith("\n", $output);
    }

    #[Test]
    public function omitsDurationAggregatesWhenThereAreNoCompletedMoves(): void
    {
        $output = (new PrometheusFormatter())->format([
            'operations' => [],
            'total' => 0,
            'failureRate' => 0.0,
            'durationMs' => ['count' => 0, 'avgMs' => null, 'minMs' => null, 'maxMs' => null],
        ]);

        self::assertStringNotContainsString('aggregate=', $output);
        self::assertStringContainsString('asset_pilot_failure_rate 0', $output);
        self::assertStringContainsString('asset_pilot_operation_duration_count 0', $output);
    }

    #[Test]
    public function escapesLabelValues(): void
    {
        $output = (new PrometheusFormatter())->format([
            'operations' => ['we"ird' => 1],
            'total' => 1,
            'failureRate' => 0.0,
            'durationMs' => ['count' => 0, 'avgMs' => null, 'minMs' => null, 'maxMs' => null],
        ]);

        self::assertStringContainsString('status="we\\"ird"', $output);
    }
}
