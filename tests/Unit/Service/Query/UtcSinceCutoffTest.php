<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\UtcSinceCutoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UtcSinceCutoff::class)]
final class UtcSinceCutoffTest extends TestCase
{
    #[Test]
    public function interpretsAnOffsetlessValueAsUtc(): void
    {
        self::assertSame('2026-07-15 10:00:00', UtcSinceCutoff::parse('2026-07-15 10:00:00'));
    }

    #[Test]
    public function normalizesAnOffsetBearingValueToUtc(): void
    {
        self::assertSame('2026-07-15 08:00:00', UtcSinceCutoff::parse('2026-07-15 10:00:00+02:00'));
    }

    #[Test]
    public function acceptsARelativeExpressionAndReturnsAUtcWallClock(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', UtcSinceCutoff::parse('-7 days'));
    }

    #[Test]
    public function rejectsAnEmptyValueRatherThanTreatingItAsNow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UtcSinceCutoff::parse('   ');
    }

    #[Test]
    public function rejectsAnUnparseableValue(): void
    {
        $this->expectException(\Exception::class);
        UtcSinceCutoff::parse('not a date');
    }
}
