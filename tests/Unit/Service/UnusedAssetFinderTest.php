<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
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

    /**
     * @param array<int, Asset> $assetsById
     */
    private function moveFinder(array $assetsById, bool $referenced, bool $folderAllowed = true): UnusedAssetFinder
    {
        $folder = $this->createMock(Asset\Folder::class);
        $folder->method('isAllowed')->willReturn($folderAllowed);

        return new class ($this->createMock(Connection::class), new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), $assetsById, $referenced, $folder) extends UnusedAssetFinder {
            /** @param array<int, Asset> $assetsById */
            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, private array $assetsById, private bool $referenced, private Asset\Folder $folder)
            {
                parent::__construct($c, $l, $s, $d);
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

            protected function isReferenced(int $assetId): bool
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
}
