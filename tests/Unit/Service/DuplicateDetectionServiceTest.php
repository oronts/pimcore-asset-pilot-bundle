<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(DuplicateDetectionService::class)]
class DuplicateDetectionServiceTest extends TestCase
{
    private function asset(int $id, bool $folder = false): Asset
    {
        $asset = $this->createMock($folder ? Asset\Folder::class : Asset::class);
        $asset->method('getId')->willReturn($id);

        return $asset;
    }

    /**
     * @param list<int>             $idsToScan
     * @param array<int, ?Asset>    $assetsById
     * @param array<int, string>    $checksumById
     * @param array<int, int>       $sizeById
     * @param \ArrayObject<int, array{0:int,1:string,2:int}> $upserts
     * @param list<array{checksum: string, file_size: int, cnt: int}> $duplicateRows
     * @param array<string, list<int>> $idsByChecksum
     */
    private function service(
        array $idsToScan = [],
        array $assetsById = [],
        array $checksumById = [],
        array $sizeById = [],
        ?\ArrayObject $upserts = null,
        array $duplicateRows = [],
        array $idsByChecksum = [],
    ): DuplicateDetectionService {
        $upserts ??= new \ArrayObject();

        return new class ($idsToScan, $assetsById, $checksumById, $sizeById, $upserts, $duplicateRows, $idsByChecksum) extends DuplicateDetectionService {
            /**
             * @param list<int>          $idsToScan
             * @param array<int, ?Asset> $assetsById
             * @param array<int, string> $checksumById
             * @param array<int, int>    $sizeById
             * @param \ArrayObject<int, array{0:int,1:string,2:int}> $upserts
             * @param list<array{checksum: string, file_size: int, cnt: int}> $duplicateRows
             * @param array<string, list<int>> $idsByChecksum
             */
            public function __construct(
                private readonly array $idsToScan,
                private readonly array $assetsById,
                private readonly array $checksumById,
                private readonly array $sizeById,
                private readonly \ArrayObject $upserts,
                private readonly array $duplicateRows,
                private readonly array $idsByChecksum,
            ) {
                parent::__construct(
                    (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(),
                    new NullLogger(),
                );
            }

            protected function listAssetIds(array $filters, int $offset, int $limit): array
            {
                return array_slice($this->idsToScan, $offset, $limit);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }

            protected function checksumOf(Asset $asset): string
            {
                return $this->checksumById[(int) $asset->getId()] ?? '';
            }

            protected function fileSizeOf(Asset $asset): int
            {
                return $this->sizeById[(int) $asset->getId()] ?? 0;
            }

            protected function upsert(int $assetId, string $checksum, int $fileSize): void
            {
                $this->upserts->append([$assetId, $checksum, $fileSize]);
            }

            protected function fetchDuplicateRows(int $offset, int $limit, int $minCopies = 2, ?string $type = null): array
            {
                return array_slice($this->duplicateRows, $offset, $limit);
            }

            protected function assetIdsForChecksum(string $checksum, int $cap): array
            {
                return array_slice($this->idsByChecksum[$checksum] ?? [], 0, $cap);
            }
        };
    }

    #[Test]
    public function indexHashesEachAssetAndUpsertsIt(): void
    {
        $upserts = new \ArrayObject();
        $stats = $this->service(
            idsToScan: [1, 2],
            assetsById: [1 => $this->asset(1), 2 => $this->asset(2)],
            checksumById: [1 => 'aaa', 2 => 'bbb'],
            sizeById: [1 => 10, 2 => 20],
            upserts: $upserts,
        )->index();

        self::assertSame(['scanned' => 2, 'indexed' => 2, 'skipped' => 0], $stats);
        self::assertSame([[1, 'aaa', 10], [2, 'bbb', 20]], $upserts->getArrayCopy());
    }

    #[Test]
    public function indexSkipsFoldersMissingAndUnhashableAssets(): void
    {
        $upserts = new \ArrayObject();
        $stats = $this->service(
            idsToScan: [1, 2, 3, 4],
            assetsById: [1 => $this->asset(1), 2 => $this->asset(2, folder: true), 4 => $this->asset(4)],
            checksumById: [1 => 'aaa', 4 => ''], // id 3 missing; id 4 unhashable
            sizeById: [1 => 10],
            upserts: $upserts,
        )->index();

        self::assertSame(['scanned' => 4, 'indexed' => 1, 'skipped' => 3], $stats);
        self::assertSame([[1, 'aaa', 10]], $upserts->getArrayCopy());
    }

    #[Test]
    public function indexIsBoundedByTheLimit(): void
    {
        $stats = $this->service(
            idsToScan: [1, 2, 3, 4, 5],
            assetsById: array_map(fn (int $id): Asset => $this->asset($id), array_combine([1, 2, 3, 4, 5], [1, 2, 3, 4, 5])),
            checksumById: [1 => 'a', 2 => 'b', 3 => 'c', 4 => 'd', 5 => 'e'],
        )->index(limit: 2);

        self::assertSame(2, $stats['scanned']);
        self::assertSame(2, $stats['indexed']);
    }

    #[Test]
    public function findDuplicatesMapsGroupedRowsToDuplicateGroups(): void
    {
        $groups = $this->service(
            duplicateRows: [
                ['checksum' => 'aaa', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'bbb', 'file_size' => 40, 'cnt' => 2],
            ],
            idsByChecksum: ['aaa' => [1, 2, 3], 'bbb' => [7, 8]],
        )->findDuplicates();

        self::assertCount(2, $groups);
        self::assertSame('aaa', $groups[0]->checksum);
        self::assertSame(10, $groups[0]->fileSize);
        self::assertSame(3, $groups[0]->count);
        self::assertSame([1, 2, 3], $groups[0]->assetIds);
        self::assertSame([7, 8], $groups[1]->assetIds);
    }

    #[Test]
    public function findDuplicatesExcludesRowsForDeletedAssetsViaTheJoin(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE asset_pilot_checksum (asset_id INTEGER, checksum TEXT, file_size INTEGER, indexed_at TEXT)');
        // assets 1,2 exist and share "dup"; assets 3,4 share "ghost" but 4 was deleted (no assets row).
        foreach ([1, 2, 3] as $id) {
            $connection->insert('assets', ['id' => $id]);
        }
        foreach ([[1, 'dup'], [2, 'dup'], [3, 'ghost'], [4, 'ghost']] as [$assetId, $checksum]) {
            $connection->insert('asset_pilot_checksum', ['asset_id' => $assetId, 'checksum' => $checksum, 'file_size' => 100, 'indexed_at' => '2026-06-19 00:00:00']);
        }

        $service = new DuplicateDetectionService($connection, new NullLogger());

        $groups = $service->findDuplicates();
        self::assertCount(1, $groups, 'the "ghost" group has only one live asset and must not appear');
        self::assertSame('dup', $groups[0]->checksum);
        self::assertSame([1, 2], $groups[0]->assetIds);
        self::assertSame(1, $service->countDuplicateGroups());
    }

    #[Test]
    public function groupForChecksumReturnsLiveMembersOrNullWhenTooFewRemain(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE asset_pilot_checksum (asset_id INTEGER, checksum TEXT, file_size INTEGER, indexed_at TEXT)');
        // assets 1,2 live and share "dup"; "ghost" has only one live member (4 was deleted).
        foreach ([1, 2, 3] as $id) {
            $connection->insert('assets', ['id' => $id]);
        }
        foreach ([[1, 'dup', 90], [2, 'dup', 100], [3, 'ghost', 50], [4, 'ghost', 50]] as [$assetId, $checksum, $size]) {
            $connection->insert('asset_pilot_checksum', ['asset_id' => $assetId, 'checksum' => $checksum, 'file_size' => $size, 'indexed_at' => '2026-06-19 00:00:00']);
        }

        $service = new DuplicateDetectionService($connection, new NullLogger());

        $group = $service->groupForChecksum('dup');
        self::assertNotNull($group);
        self::assertSame('dup', $group->checksum);
        self::assertSame([1, 2], $group->assetIds);
        self::assertSame(2, $group->count);
        self::assertSame(90, $group->fileSize, 'reports the smallest indexed size in the group');

        self::assertNull($service->groupForChecksum('ghost'), 'a single live member is not a duplicate group');
        self::assertNull($service->groupForChecksum(''), 'an empty checksum yields no group');
    }

    #[Test]
    public function groupForAssetResolvesViaTheIndexedChecksum(): void
    {
        $sentinel = new DuplicateGroup('aaa', 100, 2, [5, 6]);

        $service = new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger(), $sentinel) extends DuplicateDetectionService {
            public function __construct(Connection $c, NullLogger $l, private readonly DuplicateGroup $sentinel)
            {
                parent::__construct($c, $l);
            }

            protected function indexedChecksumFor(int $assetId): ?string
            {
                return $assetId === 5 ? 'aaa' : null;
            }

            public function groupForChecksum(string $checksum): ?DuplicateGroup
            {
                return $checksum === 'aaa' ? $this->sentinel : null;
            }
        };

        self::assertSame($sentinel, $service->groupForAsset(5));
        self::assertNull($service->groupForAsset(9), 'an unindexed asset has no duplicate group');
    }
}
