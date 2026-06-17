<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller\Support;

use Oronts\AssetPilotBundle\Controller\Api\Support\BulkIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BulkIds::class)]
class BulkIdsTest extends TestCase
{
    #[Test]
    public function castsToIntAndReturnsAList(): void
    {
        self::assertSame([1, 2, 3], BulkIds::clean(['1', '2', 3]));
    }

    #[Test]
    public function deduplicatesPreservingFirstSeenOrder(): void
    {
        self::assertSame([1, 2], BulkIds::clean([1, 1, 2, 2, 1]));
    }

    #[Test]
    public function dropsZeroAndNegativeIds(): void
    {
        self::assertSame([3], BulkIds::clean([0, -5, 3, '-1']));
    }

    #[Test]
    public function reindexesSparseInput(): void
    {
        self::assertSame([10, 20], BulkIds::clean([5 => 10, 9 => 20]));
    }

    #[Test]
    public function returnsEmptyForNonArrayOrEmpty(): void
    {
        self::assertSame([], BulkIds::clean(null));
        self::assertSame([], BulkIds::clean('nope'));
        self::assertSame([], BulkIds::clean([]));
        self::assertSame([], BulkIds::clean([0, -1]));
    }

    #[Test]
    public function exposesAPositiveCap(): void
    {
        self::assertGreaterThan(0, BulkIds::MAX);
    }

    #[Test]
    public function dropsNonIntegerJunk(): void
    {
        self::assertSame([7], BulkIds::clean([true, false, ['x'], 3.5, '1.5', 'abc', null, 7]));
    }

    #[Test]
    public function acceptsDigitStringsButNotSignedOrSpaced(): void
    {
        self::assertSame([5], BulkIds::clean(['5', ' 6', '-7']));
    }

    #[Test]
    public function stopsCollectingJustAboveTheCapSoTheCallerCanReject(): void
    {
        $raw = range(1, BulkIds::MAX + 500);
        $cleaned = BulkIds::clean($raw);

        self::assertCount(BulkIds::MAX + 1, $cleaned);
    }
}
