<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
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
     * @param array<int, int|null>  $sizeById
     * @param \ArrayObject          $upserts
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
        ?array $visibleIds = null,
        int $groupScanBudget = 5000,
    ): DuplicateDetectionService {
        $upserts ??= new \ArrayObject();

        $connection = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        [$authorization, $workspaceScope] = $this->security($connection, $visibleIds);

        return new class ($idsToScan, $assetsById, $checksumById, $sizeById, $upserts, $duplicateRows, $idsByChecksum, $connection, $authorization, $workspaceScope, $visibleIds, $groupScanBudget) extends DuplicateDetectionService {
            /**
             * @param list<int>          $idsToScan
             * @param array<int, ?Asset> $assetsById
             * @param array<int, string> $checksumById
             * @param array<int, int|null> $sizeById
             * @param \ArrayObject $upserts
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
                Connection $connection,
                ElementAuthorization $authorization,
                AssetWorkspaceQueryScope $workspaceScope,
                private readonly ?array $visibleIds,
                int $groupScanBudget,
            ) {
                parent::__construct(
                    $connection,
                    new NullLogger(),
                    $authorization,
                    $workspaceScope,
                    $groupScanBudget,
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

            protected function fileSizeOf(Asset $asset): ?int
            {
                $id = (int) $asset->getId();

                return array_key_exists($id, $this->sizeById) ? $this->sizeById[$id] : 0;
            }

            protected function upsert(int $assetId, string $checksum, ?int $fileSize): void
            {
                $this->upserts->append([$assetId, $checksum, $fileSize]);
            }

            protected function fetchDuplicateRows(int $offset, int $limit, int $minCopies = 2, ?string $type = null, array $filters = []): array
            {
                return array_slice($this->duplicateRows, $offset, $limit);
            }

            protected function assetIdsForChecksum(string $checksum, int $cap, ?string $type = null, array $filters = []): array
            {
                return array_slice($this->idsByChecksum[$checksum] ?? [], 0, $cap);
            }

            protected function isAssetVisible(int $assetId): bool
            {
                return $this->visibleIds === null || in_array($assetId, $this->visibleIds, true);
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
    public function indexPreservesAnUnknownStorageSize(): void
    {
        $upserts = new \ArrayObject();
        $stats = $this->service(
            idsToScan: [1],
            assetsById: [1 => $this->asset(1)],
            checksumById: [1 => 'aaa'],
            sizeById: [1 => null],
            upserts: $upserts,
        )->index();

        self::assertSame(1, $stats['indexed']);
        self::assertSame([[1, 'aaa', null]], $upserts->getArrayCopy());
    }

    #[Test]
    public function findDuplicatePageFillsAcrossHiddenGroupsAndFlagsAVisibleSurplus(): void
    {
        // Groups B and D have no natively-visible members, so the page must still be filled from the
        // visible groups (A, C) and hasMore set only because a further visible group (E) exists.
        $service = $this->service(
            duplicateRows: [
                ['checksum' => 'A', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'B', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'C', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'D', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'E', 'file_size' => 10, 'cnt' => 3],
            ],
            idsByChecksum: ['A' => [1, 2, 3], 'B' => [4, 5, 6], 'C' => [7, 8, 9], 'D' => [10, 11, 12], 'E' => [13, 14, 15]],
            visibleIds: [1, 2, 3, 7, 8, 9, 13, 14, 15],
        );

        $result = $service->findDuplicatePage(1, 2, 2);

        self::assertSame(['A', 'C'], array_map(static fn (DuplicateGroup $g): string => $g->checksum, $result['groups']));
        self::assertTrue($result['hasMore'], 'a further visible group (E) exists past the page');
    }

    #[Test]
    public function findDuplicatePageReportsNoMoreWhenVisibleGroupsAreExhausted(): void
    {
        $service = $this->service(
            duplicateRows: [
                ['checksum' => 'A', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'B', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'C', 'file_size' => 10, 'cnt' => 3],
            ],
            idsByChecksum: ['A' => [1, 2, 3], 'B' => [4, 5, 6], 'C' => [7, 8, 9]],
            visibleIds: [1, 2, 3, 7, 8, 9],
        );

        $result = $service->findDuplicatePage(1, 2, 2);

        self::assertSame(['A', 'C'], array_map(static fn (DuplicateGroup $g): string => $g->checksum, $result['groups']));
        self::assertFalse($result['hasMore']);
        self::assertFalse($result['truncated'], 'a genuinely exhausted group scan is a real end, not a budget truncation');
    }

    #[Test]
    public function findDuplicatePageFlagsTruncatedWhenTheGroupScanBudgetIsExhausted(): void
    {
        $rows = array_map(
            static fn (int $i): array => ['checksum' => 'H' . $i, 'file_size' => 10, 'cnt' => 3],
            range(1, 600),
        );
        $service = $this->service(duplicateRows: $rows, idsByChecksum: [], visibleIds: [], groupScanBudget: 200);

        $result = $service->findDuplicatePage(1, 2, 2);

        self::assertSame([], $result['groups']);
        self::assertFalse($result['hasMore']);
        self::assertTrue($result['truncated'], 'hitting the exact group scan budget with the page unfilled is a truncation');
    }

    #[Test]
    public function findDuplicatePageDoesNotFlagTruncatedWhenGroupsEndExactlyAtTheBudget(): void
    {
        $rows = array_map(
            static fn (int $i): array => ['checksum' => 'H' . $i, 'file_size' => 10, 'cnt' => 3],
            range(1, 1000),
        );
        $service = $this->service(duplicateRows: $rows, idsByChecksum: [], visibleIds: [], groupScanBudget: 1000);

        $result = $service->findDuplicatePage(1, 40, 2);

        self::assertSame([], $result['groups']);
        self::assertFalse($result['hasMore']);
        self::assertFalse($result['truncated'], 'a group scan that ends exactly at the budget is exhausted, not truncated');
    }

    #[Test]
    public function iterateForExportStreamsEveryVisibleGroupAndDropsHiddenOnes(): void
    {
        $service = $this->service(
            duplicateRows: [
                ['checksum' => 'A', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'B', 'file_size' => 10, 'cnt' => 3],
                ['checksum' => 'C', 'file_size' => 10, 'cnt' => 3],
            ],
            idsByChecksum: ['A' => [1, 2, 3], 'B' => [4, 5, 6], 'C' => [7, 8, 9]],
            visibleIds: [1, 2, 3, 7, 8, 9],
        );

        $export = $service->iterateForExport(2);
        $checksums = array_map(
            static fn (DuplicateGroup $group): string => $group->checksum,
            iterator_to_array($export, false),
        );

        self::assertSame(['A', 'C'], $checksums);
        self::assertFalse($export->getReturn(), 'a fully-drained export is complete, not truncated');
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
    public function workspaceFilteringRemovesHiddenMembersAndKeepsCountInSync(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT)');
        $connection->executeStatement('CREATE TABLE asset_pilot_checksum (asset_id INTEGER, checksum TEXT, file_size INTEGER, indexed_at TEXT)');
        $connection->executeStatement('CREATE TABLE users_workspaces_asset (userId INTEGER, cpath TEXT, view INTEGER)');
        foreach ([[1, '/visible/', '1.png'], [2, '/visible/', '2.png'], [3, '/secret/', '3.png'], [7, '/visible/', '7.png'], [8, '/secret/', '8.png']] as [$id, $path, $filename]) {
            $connection->insert('assets', ['id' => $id, 'path' => $path, 'filename' => $filename, 'type' => 'image']);
        }
        foreach ([[1, 'aaa'], [2, 'aaa'], [3, 'aaa'], [7, 'bbb'], [8, 'bbb']] as [$assetId, $checksum]) {
            $connection->insert('asset_pilot_checksum', ['asset_id' => $assetId, 'checksum' => $checksum, 'file_size' => 10, 'indexed_at' => '2026-07-15 00:00:00']);
        }
        $connection->insert('users_workspaces_asset', ['userId' => 7, 'cpath' => '/visible', 'view' => 1]);

        $actor = ActorContext::user(7);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $authorization->method('isAllowed')->willReturn(true);
        $actors = $this->createMock(ActorContextProvider::class);
        $actors->method('resolveUser')->with($actor)->willReturn(
            (new \Pimcore\Model\User())->setId(7)->setActive(true)->setAdmin(false)->setPermissions(['assets']),
        );
        $stubs = [];
        foreach ([1, 2, 3, 7, 8] as $id) {
            $stub = $this->createStub(Asset::class);
            $stub->method('getId')->willReturn($id);
            $stubs[$id] = $stub;
        }
        $service = new class ($connection, new NullLogger(), $authorization, new AssetWorkspaceQueryScope($connection, $authorization, $actors), $stubs) extends DuplicateDetectionService {
            /** @param array<int, Asset> $stubs */
            public function __construct(Connection $connection, NullLogger $logger, ElementAuthorization $authorization, AssetWorkspaceQueryScope $scope, private array $stubs)
            {
                parent::__construct($connection, $logger, $authorization, $scope);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->stubs[$id] ?? null;
            }
        };

        $groups = $service->findDuplicates();

        self::assertCount(1, $groups);
        self::assertSame([1, 2], $groups[0]->assetIds);
        self::assertSame(2, $groups[0]->count);
        self::assertSame(1, $service->countDuplicateGroups());
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

        $service = $this->databaseService($connection);

        $groups = $service->findDuplicates();
        self::assertCount(1, $groups, 'the "ghost" group has only one live asset and must not appear');
        self::assertSame('dup', $groups[0]->checksum);
        self::assertSame([1, 2], $groups[0]->assetIds);
        self::assertSame(1, $service->countDuplicateGroups());
    }

    #[Test]
    public function findDuplicatesWithTypeFilterReturnsOnlyThatTypesIdsAndCount(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, type TEXT)');
        $connection->executeStatement('CREATE TABLE asset_pilot_checksum (asset_id INTEGER, checksum TEXT, file_size INTEGER, indexed_at TEXT)');
        // Three live assets share the "dup" hash: two images and one document.
        foreach ([[1, 'image'], [2, 'image'], [3, 'document']] as [$id, $type]) {
            $connection->insert('assets', ['id' => $id, 'type' => $type]);
        }
        foreach ([1, 2, 3] as $assetId) {
            $connection->insert('asset_pilot_checksum', ['asset_id' => $assetId, 'checksum' => 'dup', 'file_size' => 100, 'indexed_at' => '2026-06-20 00:00:00']);
        }

        $service = $this->databaseService($connection);

        $all = $service->findDuplicates();
        self::assertCount(1, $all);
        self::assertSame(3, $all[0]->count);
        self::assertSame([1, 2, 3], $all[0]->assetIds);

        $images = $service->findDuplicates(type: 'image');
        self::assertCount(1, $images);
        self::assertSame(2, $images[0]->count, 'count reflects only the image copies');
        self::assertSame([1, 2], $images[0]->assetIds, 'ids must not include the document copy');
        self::assertSame(1, $service->countDuplicateGroups(type: 'image'));

        // The lone document copy is below minCopies once the group is type-filtered.
        self::assertSame([], $service->findDuplicates(type: 'document'));
        self::assertSame(0, $service->countDuplicateGroups(type: 'document'));
    }
    #[Test]
    public function folderFilterScopesGroupsCountsAndMemberIds(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT)');
        $connection->executeStatement('CREATE TABLE asset_pilot_checksum (asset_id INTEGER, checksum TEXT, file_size INTEGER, indexed_at TEXT)');
        foreach ([
            [1, '/wanted/', 'one.png'],
            [2, '/wanted/', 'two.png'],
            [3, '/other/', 'three.png'],
            [4, '/other/', 'four.png'],
            [5, '/other/', 'five.png'],
        ] as [$id, $path, $filename]) {
            $connection->insert('assets', ['id' => $id, 'path' => $path, 'filename' => $filename, 'type' => 'image']);
        }
        foreach ([[1, 'shared'], [2, 'shared'], [3, 'shared'], [4, 'outside'], [5, 'outside']] as [$assetId, $checksum]) {
            $connection->insert('asset_pilot_checksum', ['asset_id' => $assetId, 'checksum' => $checksum, 'file_size' => 100, 'indexed_at' => '2026-07-15 00:00:00']);
        }
        $service = $this->databaseService($connection);
        $filters = ['folder' => '/wanted'];

        $groups = $service->findDuplicates(filters: $filters);

        self::assertCount(1, $groups);
        self::assertSame(2, $groups[0]->count);
        self::assertSame([1, 2], $groups[0]->assetIds);
        self::assertSame(1, $service->countDuplicateGroups(filters: $filters));
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

        $service = $this->databaseService($connection);

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

        $connection = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        [$authorization, $workspaceScope] = $this->security($connection);
        $service = new class ($connection, new NullLogger(), $authorization, $workspaceScope, $sentinel) extends DuplicateDetectionService {
            public function __construct(Connection $c, NullLogger $l, ElementAuthorization $authorization, AssetWorkspaceQueryScope $workspaceScope, private readonly DuplicateGroup $sentinel)
            {
                parent::__construct($c, $l, $authorization, $workspaceScope);
            }

            protected function indexedChecksumFor(int $assetId): ?string
            {
                return $assetId === 5 ? 'aaa' : null;
            }

            public function groupForChecksum(string $checksum): ?DuplicateGroup
            {
                return $checksum === 'aaa' ? $this->sentinel : null;
            }

            protected function isAssetVisible(int $assetId): bool
            {
                return true;
            }
        };

        self::assertSame($sentinel, $service->groupForAsset(5));
        self::assertNull($service->groupForAsset(9), 'an unindexed asset has no duplicate group');
    }

    private function databaseService(Connection $connection): DuplicateDetectionService
    {
        [$authorization, $workspaceScope] = $this->security($connection);

        return new class ($connection, new NullLogger(), $authorization, $workspaceScope) extends DuplicateDetectionService {
            protected function isAssetVisible(int $assetId): bool
            {
                return true;
            }
        };
    }

    /** @param list<int>|null $visibleIds @return array{ElementAuthorization, AssetWorkspaceQueryScope} */
    private function security(Connection $connection, ?array $visibleIds = null): array
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::system());
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset): bool => $visibleIds === null || in_array((int) $asset->getId(), $visibleIds, true),
        );

        return [
            $authorization,
            new AssetWorkspaceQueryScope($connection, $authorization, $this->createMock(ActorContextProvider::class)),
        ];
    }
}
