<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Api\Serialization;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiDateFormatter::class)]
class ApiDateFormatterTest extends TestCase
{
    #[Test]
    public function serializesAUtcDatabaseValueToRfc3339(): void
    {
        self::assertSame('2026-07-17T20:04:05+00:00', (new ApiDateFormatter())->fromDatabase('2026-07-17 20:04:05'));
    }

    #[Test]
    public function aNullOrEmptyStoredValueSerializesToNull(): void
    {
        $formatter = new ApiDateFormatter();
        self::assertNull($formatter->fromDatabase(null));
        self::assertNull($formatter->fromDatabase(''));
    }

    #[Test]
    public function aCorruptStoredValueThrowsRatherThanEmittingNullAgainstANonNullableSchema(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ApiDateFormatter())->fromDatabase('not-a-date');
    }

    #[Test]
    public function interpretsTheStoredValueAsUtcRegardlessOfTheAmbientTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            self::assertSame('2026-07-17T20:04:05+00:00', (new ApiDateFormatter())->fromDatabase('2026-07-17 20:04:05'));
        } finally {
            date_default_timezone_set($original);
        }
    }

    #[Test]
    public function normalizesATypedInstantOfAnyTimezoneToUtc(): void
    {
        $instant = new \DateTimeImmutable('2026-07-17 16:04:05', new \DateTimeZone('America/New_York'));
        self::assertSame('2026-07-17T20:04:05+00:00', (new ApiDateFormatter())->fromInstant($instant));
    }
}
