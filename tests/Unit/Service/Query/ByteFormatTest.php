<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ByteFormat::class)]
class ByteFormatTest extends TestCase
{
    #[Test]
    public function formatsCommonSizes(): void
    {
        self::assertSame('0 B', ByteFormat::human(0));
        self::assertSame('0 B', ByteFormat::human(-5));
        self::assertSame('512 B', ByteFormat::human(512));
        self::assertSame('1 KB', ByteFormat::human(1024));
        self::assertSame('1.5 KB', ByteFormat::human(1536));
        self::assertSame('1 MB', ByteFormat::human(1024 ** 2));
        self::assertSame('1 GB', ByteFormat::human(1024 ** 3));
    }

    #[Test]
    public function formatsPetabyteAndExabyteSizesThatOverflowedTheOldUnitList(): void
    {
        // The previous 5-unit list (B..TB) indexed out of bounds at PB+; these are valid ints.
        self::assertSame('1 PB', ByteFormat::human(1024 ** 5));
        self::assertSame('1 EB', ByteFormat::human(1024 ** 6));
    }
}
