<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(UnusedAssetFinder::class)]
class UnusedAssetFinderTest extends TestCase
{
    private function finder(): UnusedAssetFinder
    {
        return new UnusedAssetFinder(
            $this->createMock(Connection::class),
            new NullLogger(),
            $this->createMock(ConfidenceScorer::class),
            new EventDispatcher(),
        );
    }

    #[Test]
    public function findUnusedRejectsMinSizeFilterInsteadOfSilentlyIgnoringIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder()->findUnused(['minSize' => 1024]);
    }

    #[Test]
    public function findUnusedRejectsMaxSizeFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder()->findUnused(['maxSize' => 1024]);
    }

    #[Test]
    public function countUnusedRejectsSizeFilters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder()->countUnused(['minSize' => 1, 'maxSize' => 2]);
    }

    #[Test]
    public function aggregateStatsSumsRealSizesPerTypeAndTotal(): void
    {
        $finder = $this->finderWithSizes([1 => 100, 2 => 50, 3 => 800]);

        $stats = $finder->aggregate([
            ['id' => 1, 'type' => 'image'],
            ['id' => 2, 'type' => 'image'],
            ['id' => 3, 'type' => 'video'],
        ]);

        self::assertSame(3, $stats['totalCount']);
        self::assertSame(950, $stats['totalSize']);
        // Ordered by count DESC: image (2) before video (1).
        self::assertSame('image', $stats['byType'][0]['type']);
        self::assertSame(2, $stats['byType'][0]['count']);
        self::assertSame(150, $stats['byType'][0]['total_size']);
        self::assertSame('video', $stats['byType'][1]['type']);
        self::assertSame(800, $stats['byType'][1]['total_size']);
    }

    #[Test]
    public function aggregateStatsHandlesNoUnusedAssets(): void
    {
        $stats = $this->finderWithSizes([])->aggregate([]);

        self::assertSame(0, $stats['totalCount']);
        self::assertSame(0, $stats['totalSize']);
        self::assertSame([], $stats['byType']);
    }

    /** @param array<int, int> $sizes */
    private function finderWithSizes(array $sizes): object
    {
        return new class($this->createMock(Connection::class), new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), $sizes) extends UnusedAssetFinder {
            /** @param array<int, int> $sizes */
            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, private array $sizes)
            {
                parent::__construct($c, $l, $s, $d);
            }

            protected function fileSize(int $assetId): int
            {
                return $this->sizes[$assetId] ?? 0;
            }

            /** @param list<array{id: mixed, type: mixed}> $rows */
            public function aggregate(array $rows): array
            {
                return $this->aggregateStats($rows);
            }
        };
    }
}
