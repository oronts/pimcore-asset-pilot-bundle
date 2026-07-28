<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller\Support;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Controller\Api\Support\AuditRowDates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditRowDates::class)]
final class AuditRowDatesTest extends TestCase
{
    #[Test]
    public function normalizesEveryAuditTimestampColumnToRfc3339Utc(): void
    {
        $row = [
            'id' => 7,
            'status' => 'completed',
            'created_at' => '2026-07-15 10:00:00',
            'updated_at' => '2026-07-15 10:00:05',
            'committed_at' => '2026-07-15 10:00:06',
        ];

        $normalized = AuditRowDates::normalize($row, new ApiDateFormatter());

        self::assertSame('2026-07-15T10:00:00+00:00', $normalized['created_at']);
        self::assertSame('2026-07-15T10:00:05+00:00', $normalized['updated_at']);
        self::assertSame('2026-07-15T10:00:06+00:00', $normalized['committed_at']);
        self::assertSame(7, $normalized['id']);
        self::assertSame('completed', $normalized['status']);
    }

    #[Test]
    public function passesNullableTimestampsThroughAsNull(): void
    {
        $row = [
            'created_at' => '2026-07-15 10:00:00',
            'updated_at' => null,
            'committed_at' => null,
        ];

        $normalized = AuditRowDates::normalize($row, new ApiDateFormatter());

        self::assertSame('2026-07-15T10:00:00+00:00', $normalized['created_at']);
        self::assertNull($normalized['updated_at']);
        self::assertNull($normalized['committed_at']);
    }

    #[Test]
    public function leavesRowsWithoutTimestampColumnsUntouched(): void
    {
        $row = ['id' => 1, 'status' => 'pending'];

        self::assertSame($row, AuditRowDates::normalize($row, new ApiDateFormatter()));
    }
}
