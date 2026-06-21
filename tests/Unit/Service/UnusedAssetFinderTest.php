<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
    public function unusedPredicateUsesCorrelatedNotExistsNotNotIn(): void
    {
        // A lazy real-platform connection (never opened) so getSQL() renders the predicate; no live DB.
        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'dbname' => 'x', 'user' => 'x', 'password' => 'x', 'serverVersion' => '8.0.0',
        ]);
        $qb = $connection->createQueryBuilder()->select('a.id')->from('assets', 'a');

        $method = new \ReflectionMethod(UnusedAssetFinder::class, 'applyUnusedPredicate');
        $method->invoke($this->finder(), $qb);

        $sql = $qb->getSQL();
        self::assertStringContainsStringIgnoringCase('NOT EXISTS', $sql, 'the unused predicate must use a correlated NOT EXISTS');
        self::assertStringNotContainsStringIgnoringCase('NOT IN', $sql, 'NOT IN degrades to a full dependencies scan and mishandles NULL targetid');
        self::assertStringContainsString('d.targetid = a.id', $sql, 'the subquery must be correlated to the outer asset row');
        self::assertStringContainsString('d.targettype = :assetType', $sql);
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
        $finder = $this->finderWithSizes(['/p/a.jpg' => 100, '/p/b.jpg' => 50, '/p/c.mp4' => 800]);

        $stats = $finder->aggregate([
            ['id' => 1, 'type' => 'image', 'path' => '/p/', 'filename' => 'a.jpg'],
            ['id' => 2, 'type' => 'image', 'path' => '/p/', 'filename' => 'b.jpg'],
            ['id' => 3, 'type' => 'video', 'path' => '/p/', 'filename' => 'c.mp4'],
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

    /** @param array<string, int> $sizes keyed by full path */
    private function finderWithSizes(array $sizes): object
    {
        return new class ($this->createMock(Connection::class), new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), $sizes) extends UnusedAssetFinder {
            /** @param array<string, int> $sizes */
            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, private array $sizes)
            {
                parent::__construct($c, $l, $s, $d);
            }

            protected function fileSize(string $fullPath): int
            {
                return $this->sizes[$fullPath] ?? 0;
            }

            /** @param list<array{id: mixed, type: mixed, path: mixed, filename: mixed}> $rows */
            public function aggregate(array $rows): array
            {
                return $this->aggregateStats($rows);
            }
        };
    }

    #[Test]
    public function moveAssetsSkipsAnAssetThatBecameReferenced(): void
    {
        $result = $this->moveFinder([1 => $this->asset(true)], referenced: true)->moveAssets([1], '/Archive');

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('referenced', $result['errors'][1]);
    }

    #[Test]
    public function moveAssetsMovesAnUnreferencedAllowedAsset(): void
    {
        $result = $this->moveFinder([1 => $this->asset(true)], referenced: false)->moveAssets([1], '/Archive');

        self::assertSame(1, $result['moved']);
        self::assertSame(0, $result['failed']);
    }

    #[Test]
    public function moveAssetsSkipsWhenPerAssetAclDenies(): void
    {
        $result = $this->moveFinder([1 => $this->asset(false)], referenced: false)->moveAssets([1], '/Archive');

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function moveAssetsAbortsWhenTargetFolderAclDenies(): void
    {
        $result = $this->moveFinder([1 => $this->asset(true)], referenced: false, folderAllowed: false)->moveAssets([1], '/Archive');

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function deleteAssetsSkipsAnAssetThatBecameReferenced(): void
    {
        $result = $this->moveFinder([1 => $this->asset(true)], referenced: true)->deleteAssets([1]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('referenced', $result['errors'][1]);
    }

    #[Test]
    public function deleteAssetsSkipsAnAssetReferencedInContent(): void
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('isReferencedInContent')->willReturn(true);

        $result = $this->moveFinder([1 => $this->asset(true)], referenced: false, scanner: $scanner)->deleteAssets([1]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('content', $result['errors'][1]);
    }

    #[Test]
    public function moveAssetsSkipsAnAssetReferencedInContent(): void
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('isReferencedInContent')->willReturn(true);

        $result = $this->moveFinder([1 => $this->asset(true)], referenced: false, scanner: $scanner)->moveAssets([1], '/Archive');

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('content', $result['errors'][1]);
    }

    #[Test]
    public function previewMutationReportsTheGuardOutcomeWithoutActing(): void
    {
        self::assertNull($this->moveFinder([1 => $this->asset(true)], referenced: false)->previewMutation(1, 'delete'));
        self::assertSame('Asset is now referenced by an object', $this->moveFinder([1 => $this->asset(true)], referenced: true)->previewMutation(1, 'delete'));
        self::assertSame('Not permitted to move this asset', $this->moveFinder([1 => $this->asset(false)], referenced: false)->previewMutation(1, 'move'));
        self::assertSame('Asset not found', $this->moveFinder([], referenced: false)->previewMutation(999, 'delete'));
    }

    /**
     * @param array<int, Asset> $assetsById
     */
    private function moveFinder(array $assetsById, bool $referenced, bool $folderAllowed = true, ?ContentUsageScanner $scanner = null): UnusedAssetFinder
    {
        $folder = $this->createMock(Asset\Folder::class);
        $folder->method('isAllowed')->willReturn($folderAllowed);

        return new class ($this->createMock(Connection::class), new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), $assetsById, $referenced, $folder, $scanner) extends UnusedAssetFinder {
            /** @param array<int, Asset> $assetsById */
            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, private array $assetsById, private bool $referenced, private Asset\Folder $folder, ?ContentUsageScanner $scanner)
            {
                parent::__construct($c, $l, $s, $d, contentScanner: $scanner);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }

            protected function createTargetFolder(string $path): Asset\Folder
            {
                return $this->folder;
            }

            protected function nearestExistingFolder(string $path): ?Asset\Folder
            {
                return $this->folder;
            }

            public function isReferenced(int $assetId): bool
            {
                return $this->referenced;
            }
        };
    }

    private function asset(bool $allowed): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('isAllowed')->willReturn($allowed);
        $asset->method('getRealFullPath')->willReturn('/p/x.jpg');

        return $asset;
    }

    #[Test]
    public function getUnusedStatsCachedComputesOnceWithinTtl(): void
    {
        $finder = $this->cachingFinder(60);

        $finder->getUnusedStatsCached();
        $finder->getUnusedStatsCached();

        self::assertSame(1, $finder->computeCalls, 'the second call is served from the cache');
    }

    #[Test]
    public function getUnusedStatsCachedAlwaysComputesWhenTtlIsZero(): void
    {
        $finder = $this->cachingFinder(0);

        $finder->getUnusedStatsCached();
        $finder->getUnusedStatsCached();

        self::assertSame(2, $finder->computeCalls, 'ttl 0 keeps the schedule/maintenance callers on live data');
    }

    #[Test]
    public function deleteAssetsBustsTheUnusedStatsCache(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('isAllowed')->willReturn(true);
        $asset->method('getRealFullPath')->willReturn('/p/x.jpg');

        $finder = new class ($this->createMock(Connection::class), new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), new StatsCache(new ArrayAdapter()), $asset) extends UnusedAssetFinder {
            public int $computeCalls = 0;

            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, StatsCache $cache, private readonly Asset $asset)
            {
                parent::__construct($c, $l, $s, $d, statsCache: $cache, statsTtl: 60);
            }

            public function getUnusedStats(): array
            {
                ++$this->computeCalls;

                return ['totalCount' => $this->computeCalls, 'totalSize' => 0, 'totalSizeFormatted' => '0 B', 'byType' => []];
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->asset;
            }

            public function isReferenced(int $assetId): bool
            {
                return false;
            }
        };

        $finder->getUnusedStatsCached();
        $finder->deleteAssets([1]);
        $finder->getUnusedStatsCached();

        self::assertSame(2, $finder->computeCalls, 'a delete evicts the stale stats so the next read recomputes');
    }

    private function cachingFinder(int $ttl): UnusedAssetFinder
    {
        return new class ($this->createMock(Connection::class), new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), new StatsCache(new ArrayAdapter()), $ttl) extends UnusedAssetFinder {
            public int $computeCalls = 0;

            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, StatsCache $cache, int $ttl)
            {
                parent::__construct($c, $l, $s, $d, statsCache: $cache, statsTtl: $ttl);
            }

            public function getUnusedStats(): array
            {
                ++$this->computeCalls;

                return ['totalCount' => $this->computeCalls, 'totalSize' => 0, 'totalSizeFormatted' => '0 B', 'byType' => []];
            }
        };
    }
}
