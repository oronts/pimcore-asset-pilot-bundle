<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\IndexedAssetSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexedAssetSize::class)]
class IndexedAssetSizeTest extends TestCase
{
    #[Test]
    public function knownZeroByteFileRemainsDistinctFromUnknown(): void
    {
        self::assertSame(0, IndexedAssetSize::bytes([
            'indexed_size_known' => 1,
            'indexed_file_size' => 0,
            'size_indexed_at' => '2026-07-14 12:00:00',
            'modified_at' => strtotime('2026-07-14 11:00:00'),
        ]));
        self::assertNull(IndexedAssetSize::bytes([
            'indexed_size_known' => 0,
            'indexed_file_size' => 0,
            'size_indexed_at' => '2026-07-14 12:00:00',
            'modified_at' => strtotime('2026-07-14 11:00:00'),
        ]));
    }

    #[Test]
    public function indexOlderThanTheAssetIsUnknown(): void
    {
        self::assertNull(IndexedAssetSize::bytes([
            'indexed_size_known' => 1,
            'indexed_file_size' => 100,
            'size_indexed_at' => '2026-07-14 10:00:00',
            'modified_at' => strtotime('2026-07-14 11:00:00'),
        ]));
    }
}
