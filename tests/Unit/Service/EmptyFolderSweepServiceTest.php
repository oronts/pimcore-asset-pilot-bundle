<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(EmptyFolderSweepService::class)]
class EmptyFolderSweepServiceTest extends TestCase
{
    private function folder(int $id, bool $hasChildren, bool $allowed): Asset\Folder
    {
        $folder = $this->createMock(Asset\Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('hasChildren')->willReturn($hasChildren);
        $folder->method('isAllowed')->willReturn($allowed);
        $folder->method('getRealFullPath')->willReturn('/folder/' . $id);
        $folder->method('getModificationDate')->willReturn(1_752_572_800);

        return $folder;
    }

    /**
     * @param array<int, ?Asset\Folder>                  $foldersById
     * @param \ArrayObject<int, int>                     $deleted
     * @param list<array{id: int, full_path: string}>    $rows
     */
    private function service(array $foldersById = [], ?\ArrayObject $deleted = null, array $rows = [], ?array $visibleIds = null, ?LoopGuard $loopGuard = null): EmptyFolderSweepService
    {
        $deleted ??= new \ArrayObject();

        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset, string $permission): bool => $permission === 'view' && $visibleIds !== null
                ? in_array((int) $asset->getId(), $visibleIds, true)
                : $asset->isAllowed($permission),
        );
        if ($loopGuard === null) {
            $loopGuard = $this->createMock(LoopGuard::class);
            $loopGuard->method('acquireAsset')->willReturn(true);
        }
        $connection = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $workspaceScope = new AssetWorkspaceQueryScope(
            $connection,
            $authorization,
            $this->createMock(ActorContextProvider::class),
        );

        return new class ($foldersById, $deleted, $rows, $connection, $authorization, $loopGuard, $workspaceScope) extends EmptyFolderSweepService {
            /**
             * @param array<int, ?Asset\Folder>               $foldersById
             * @param \ArrayObject<int, int>                  $deleted
             * @param list<array{id: int, full_path: string}> $rows
             */
            public function __construct(private readonly array $foldersById, private readonly \ArrayObject $deleted, private readonly array $rows, Connection $connection, ElementAuthorization $authorization, LoopGuard $loopGuard, AssetWorkspaceQueryScope $workspaceScope)
            {
                parent::__construct($connection, new NullLogger(), $authorization, $loopGuard, $workspaceScope);
            }

            protected function loadFolder(int $id): ?Asset\Folder
            {
                return $this->foldersById[$id] ?? null;
            }

            protected function deleteFolder(Asset\Folder $folder): void
            {
                $this->deleted->append((int) $folder->getId());
            }

            protected function deleteFolderIfStillEmpty(Asset\Folder $folder): bool
            {
                $this->deleteFolder($folder);

                return true;
            }

            protected function listEmptyFolderRows(?string $root, int $offset, int $limit): array
            {
                return array_slice($this->rows, $offset, $limit);
            }
        };
    }

    #[Test]
    public function deletesChildlessPermittedFolders(): void
    {
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted);
        $result = $this->apply($service, [5]);

        self::assertSame(1, $result['deleted']);
        self::assertSame([5], $deleted->getArrayCopy());
    }

    #[Test]
    public function skipsAFolderThatGainedChildrenSinceTheListing(): void
    {
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: true, allowed: true)], $deleted);
        $result = $this->apply($service, [5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function skipsWhenTheDatabaseRecheckFindsANewChild(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, parentId INTEGER NOT NULL)');
        $connection->insert('assets', ['id' => 9, 'parentId' => 5]);
        $folder = $this->folder(5, hasChildren: false, allowed: true);
        $deleted = new \ArrayObject();
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $authorization->method('isAllowed')->willReturn(true);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $workspaceScope = new AssetWorkspaceQueryScope(
            $connection,
            $authorization,
            $this->createMock(ActorContextProvider::class),
        );
        $service = new class ($connection, $folder, $deleted, $authorization, $loopGuard, $workspaceScope) extends EmptyFolderSweepService {
            public function __construct(Connection $connection, private readonly Asset\Folder $folder, private readonly \ArrayObject $deleted, ElementAuthorization $authorization, LoopGuard $loopGuard, AssetWorkspaceQueryScope $workspaceScope)
            {
                parent::__construct($connection, new NullLogger(), $authorization, $loopGuard, $workspaceScope);
            }

            protected function loadFolder(int $id): Asset\Folder
            {
                return $this->folder;
            }

            protected function deleteFolder(Asset\Folder $folder): void
            {
                $this->deleted->append((int) $folder->getId());
            }
        };

        $result = $this->apply($service, [5]);

        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheWorkspaceAclDeniesDeletion(): void
    {
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: false)], $deleted);
        $result = $this->apply($service, [5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertArrayHasKey(5, $result['errors']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function neverDeletesTheAssetTreeRoot(): void
    {
        $deleted = new \ArrayObject();
        $service = $this->service([], $deleted);
        $result = $this->apply($service, [1]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function findEmptyMapsRowsToIdAndPath(): void
    {
        $folders = [5 => $this->folder(5, false, true), 9 => $this->folder(9, false, true)];
        $found = $this->service($folders, rows: [['id' => 5, 'full_path' => '/a/b'], ['id' => 9, 'full_path' => '/c/d']])->findEmpty();

        self::assertSame([['id' => 5, 'path' => '/a/b'], ['id' => 9, 'path' => '/c/d']], $found['items']);
    }

    #[Test]
    public function findEmptyHidesFoldersOutsideTheWorkspace(): void
    {
        $rows = [['id' => 5, 'full_path' => '/visible'], ['id' => 9, 'full_path' => '/secret']];
        $folders = [5 => $this->folder(5, false, true), 9 => $this->folder(9, false, true)];

        $found = $this->service($folders, rows: $rows, visibleIds: [5])->findEmpty();

        self::assertSame([['id' => 5, 'path' => '/visible']], $found['items']);
    }

    #[Test]
    public function findEmptyReturnsExplicitHasMoreWithoutLeakingTheSentinel(): void
    {
        $folders = [
            5 => $this->folder(5, false, true),
            9 => $this->folder(9, false, true),
        ];

        $found = $this->service($folders, rows: [
            ['id' => 5, 'full_path' => '/a'],
            ['id' => 9, 'full_path' => '/b'],
        ])->findEmpty(limit: 1);

        self::assertTrue($found['hasMore']);
        self::assertSame([['id' => 5, 'path' => '/a']], $found['items']);
    }

    #[Test]
    public function previewReportsEligibilityWithoutDeleting(): void
    {
        $deleted = new \ArrayObject();
        $service = $this->service([
            5 => $this->folder(5, false, true),
            9 => $this->folder(9, true, true),
            10 => $this->folder(10, false, false),
        ], $deleted);

        $result = $service->previewDelete([1, 5, 9, 10, 99]);

        self::assertSame(1, $result['eligible']);
        self::assertSame(3, $result['skipped']);
        self::assertSame(1, $result['failed']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function stalePlanReleasesAllLocksInReverseOrderBeforeAnyDelete(): void
    {
        $released = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->expects(self::exactly(2))->method('releaseAsset')->willReturnCallback(
            static function (int $id) use (&$released): void {
                $released[] = $id;
            },
        );
        $deleted = new \ArrayObject();
        $service = $this->service([
            5 => $this->folder(5, false, true),
            9 => $this->folder(9, false, true),
        ], $deleted, loopGuard: $loopGuard);

        try {
            $service->deleteEmpty([9, 5], ['folder:5' => 'stale', 'folder:9' => 'stale']);
            self::fail('A stale folder plan must not be applied.');
        } catch (StaleApplyPlanException) {
        }

        self::assertSame([9, 5], $released);
        self::assertSame([], $deleted->getArrayCopy());
    }

    /** @param list<int> $ids @return array{deleted: int, skipped: int, failed: int, errors: array<int, string>} */
    private function apply(EmptyFolderSweepService $service, array $ids): array
    {
        $expected = [];
        foreach ($service->createDeletePlan($ids)->targets as $target) {
            $expected[$target->id] = $target->fingerprint;
        }

        return $service->deleteEmpty($ids, $expected);
    }
}
