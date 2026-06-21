<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(QuarantineService::class)]
class QuarantineServiceTest extends TestCase
{
    /**
     * @param array<int, ?Asset>  $assetsById
     * @param array<int, ?string> $originalPaths
     */
    private function service(
        array $assetsById,
        array $originalPaths = [],
        ?LoopGuard $loopGuard = null,
        bool $isReferenced = false,
        bool $allowCreate = true,
        array $expiredIds = [],
        ?ContentUsageScanner $contentScanner = null,
        array $assetsAtPath = [],
    ): object {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('isReferenced')->willReturn($isReferenced);

        return new class (
            $this->createMock(Connection::class),
            $loopGuard ?? $this->createMock(LoopGuard::class),
            $finder,
            new EventDispatcher(),
            new NullLogger(),
            $assetsById,
            $originalPaths,
            $allowCreate,
            $expiredIds,
            $contentScanner,
            $assetsAtPath,
        ) extends QuarantineService {
            public array $recorded = [];
            public array $removed = [];
            public array $deleted = [];

            /** @param array<int, ?Asset> $assetsById @param array<int, ?string> $originalPaths @param list<int> $expiredIds */
            public function __construct(Connection $c, LoopGuard $lg, UnusedAssetFinderInterface $f, $ed, $log, private array $assetsById, private array $originalPaths, private bool $allowCreate, private array $expiredIds, ?ContentUsageScanner $scanner, private array $assetsAtPath)
            {
                parent::__construct($c, $lg, $f, $ed, $log, contentScanner: $scanner);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }

            protected function findExpired(string $cutoff, int $limit): array
            {
                return $this->expiredIds;
            }

            protected function deleteAsset(Asset $asset): void
            {
                $this->deleted[] = $asset;
            }

            protected function resolveFolder(string $path): Asset\Folder
            {
                return new Asset\Folder();
            }

            protected function targetAllowsCreate(string $path): bool
            {
                return $this->allowCreate;
            }

            protected function recordQuarantine(int $assetId, string $originalPath): void
            {
                $this->recorded[$assetId] = $originalPath;
            }

            protected function findOriginalPath(int $assetId): ?string
            {
                return $this->originalPaths[$assetId] ?? null;
            }

            protected function assetAtPath(string $path): ?Asset
            {
                return $this->assetsAtPath[$path] ?? null;
            }

            protected function deleteQuarantineRecord(int $assetId): void
            {
                $this->removed[] = $assetId;
            }
        };
    }

    private function asset(string $path, bool $allowed = true): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('isAllowed')->willReturn($allowed);

        return $asset;
    }

    #[Test]
    public function quarantinesExistingUnusedAssetsAndRecordsTheirOrigin(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg'), 2 => null]);

        $result = $service->quarantine([1, 2]);

        self::assertSame(1, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertSame('/Products/a.jpg', $service->recorded[1]);
    }

    #[Test]
    public function skipsAssetsThatBecameReferenced(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg')], isReferenced: true);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertArrayNotHasKey(1, $service->recorded);
    }

    #[Test]
    public function skipsAssetsReferencedInContent(): void
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('isReferencedInContent')->willReturn(true);

        $service = $this->service([1 => $this->asset('/Products/a.jpg')], contentScanner: $scanner);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertArrayNotHasKey(1, $service->recorded);
    }

    #[Test]
    public function purgeSkipsAssetsReferencedInContent(): void
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('isReferencedInContent')->willReturn(true);

        $service = $this->service([1 => $this->asset('/Products/a.jpg')], expiredIds: [1], contentScanner: $scanner);

        $result = $service->purgeExpired();

        self::assertSame(0, $result['purged']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $service->deleted);
    }

    #[Test]
    public function skipsAssetsTheUserMayNotMove(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg', allowed: false)]);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function failsAllWhenTheQuarantineFolderIsNotWritable(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg')], allowCreate: false);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function restoreMovesBackAndClearsTheRecord(): void
    {
        $service = $this->service([5 => $this->asset('/Quarantine/a.jpg')], [5 => '/Products/a.jpg']);

        self::assertTrue($service->restore(5));
        self::assertSame([5], $service->removed);
    }

    #[Test]
    public function restoreThrowsNotPermittedWhenTheUserMayNotPublish(): void
    {
        $service = $this->service([5 => $this->asset('/Quarantine/a.jpg', allowed: false)], [5 => '/Products/a.jpg']);

        $this->expectException(NotPermittedException::class);
        $service->restore(5);
    }

    #[Test]
    public function restoreRefusesWhenAnotherAssetOccupiesTheOriginalPath(): void
    {
        $occupant = $this->createMock(Asset::class);
        $occupant->method('getId')->willReturn(99);

        $service = $this->service(
            [5 => $this->asset('/Quarantine/a.jpg')],
            [5 => '/Products/a.jpg'],
            assetsAtPath: ['/Products/a.jpg' => $occupant],
        );

        try {
            $service->restore(5);
            self::fail('expected a path collision to be refused');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame([], $service->removed, 'a blocked restore must not clear the quarantine record');
    }

    #[Test]
    public function restoreReturnsFalseWhenNothingIsQuarantined(): void
    {
        $service = $this->service([5 => $this->asset('/x')], []);

        self::assertFalse($service->restore(5));
        self::assertSame([], $service->removed);
    }

    #[Test]
    public function purgeHardDeletesUnusedExpiredAssetsAndClearsTheRecord(): void
    {
        $service = $this->service([1 => $this->asset('/Quarantine/a.jpg')], expiredIds: [1]);

        $result = $service->purgeExpired(30);

        self::assertSame(1, $result['purged']);
        self::assertCount(1, $service->deleted);
        self::assertSame([1], $service->removed);
    }

    #[Test]
    public function purgeSkipsAnEntryThatBecameReferenced(): void
    {
        $service = $this->service([1 => $this->asset('/Quarantine/a.jpg')], isReferenced: true, expiredIds: [1]);

        $result = $service->purgeExpired(30);

        self::assertSame(0, $result['purged']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $service->deleted);
    }

    #[Test]
    public function purgeDryRunCountsButDeletesNothing(): void
    {
        $service = $this->service([1 => $this->asset('/Quarantine/a.jpg')], expiredIds: [1]);

        $result = $service->purgeExpired(30, dryRun: true);

        self::assertSame(1, $result['purged']);
        self::assertSame([], $service->deleted);
        self::assertSame([], $service->removed);
    }

    #[Test]
    public function listQuarantinedFiltersByTypeAndKeepsSameDayRecordsOnADateOnlyBefore(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT, mimetype TEXT)');
        $connection->executeStatement('CREATE TABLE asset_pilot_quarantine (asset_id INTEGER, original_path TEXT, quarantined_at TEXT)');
        $connection->insert('assets', ['id' => 1, 'path' => '/Quarantine/', 'filename' => 'a.jpg', 'type' => 'image', 'mimetype' => 'image/jpeg']);
        $connection->insert('assets', ['id' => 2, 'path' => '/Quarantine/', 'filename' => 'b.pdf', 'type' => 'document', 'mimetype' => 'application/pdf']);
        $connection->insert('asset_pilot_quarantine', ['asset_id' => 1, 'original_path' => '/Products/a.jpg', 'quarantined_at' => '2024-01-01 14:30:00']);
        $connection->insert('asset_pilot_quarantine', ['asset_id' => 2, 'original_path' => '/Docs/b.pdf', 'quarantined_at' => '2024-01-02 09:00:00']);

        $service = new QuarantineService(
            $connection,
            $this->createMock(LoopGuard::class),
            $this->createMock(UnusedAssetFinderInterface::class),
            new EventDispatcher(),
            new NullLogger(),
        );

        $allIds = array_column($service->listQuarantined()['items'], 'asset_id');
        sort($allIds);
        self::assertSame([1, 2], $allIds);

        $images = $service->listQuarantined(filters: ['type' => 'image']);
        self::assertSame([1], array_column($images['items'], 'asset_id'));
        self::assertSame(1, $images['total']);

        $before = $service->listQuarantined(filters: ['before' => '2024-01-01']);
        self::assertSame([1], array_column($before['items'], 'asset_id'), 'a date-only before keeps the same-day 14:30 record');

        $after = $service->listQuarantined(filters: ['after' => '2024-01-02']);
        self::assertSame([2], array_column($after['items'], 'asset_id'));
    }

    #[Test]
    public function theQuarantineMoveFollowsTheLoopGuardOrdering(): void
    {
        $calls = new \ArrayObject();
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('markAssetProcessing')->willReturnCallback(static fn () => $calls->append('mark'));
        $loopGuard->method('markAssetRecentlyMoved')->willReturnCallback(static fn () => $calls->append('recentlyMoved'));
        $loopGuard->method('unmarkAssetProcessing')->willReturnCallback(static fn () => $calls->append('unmark'));

        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn('/Products/a.jpg');
        $asset->method('isAllowed')->willReturn(true);
        $asset->method('save')->willReturnCallback(function () use (&$asset, $calls) {
            $calls->append('save');

            return $asset;
        });

        $this->service([1 => $asset], loopGuard: $loopGuard)->quarantine([1]);

        self::assertSame(['mark', 'save', 'recentlyMoved', 'unmark'], $calls->getArrayCopy());
    }
}
