<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\DependencyProjectionSchema;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\AssetDeletionFenceLostException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\DbalAssetDeletionFence;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifierInterface;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\ReviewedAssetLockCoordinator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\ValidationException;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(EmptyFolderSweepService::class)]
class EmptyFolderSweepServiceTest extends TestCase
{
    private function folder(int $id, bool $hasChildren, bool $allowed, bool $locked = false): Asset\Folder
    {
        $folder = $this->createMock(Asset\Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('hasChildren')->willReturn($hasChildren);
        $folder->method('isAllowed')->willReturn($allowed);
        $folder->method('getRealFullPath')->willReturn('/folder/' . $id);
        $folder->method('getModificationDate')->willReturn(1_752_572_800);
        $folder->method('hasProperty')->willReturn($locked);
        $folder->method('getProperty')->willReturn($locked);

        return $folder;
    }

    /**
     * @param array<int, ?Asset\Folder>                  $foldersById
     * @param \ArrayObject<int, int>                     $deleted
     * @param list<array{id: int, full_path: string}>    $rows
     */
    private function service(array $foldersById = [], ?\ArrayObject $deleted = null, array $rows = [], ?array $visibleIds = null, ?LoopGuard $loopGuard = null, array $referencedIds = [], ?AssetDeletionFenceInterface $fence = null, ?DependencyUsageVerifierInterface $verifier = null, ?EventDispatcher $dispatcher = null): EmptyFolderSweepService
    {
        $deleted ??= new \ArrayObject();

        if ($fence === null) {
            $fence = $this->createMock(AssetDeletionFenceInterface::class);
            $fence->method('acquire')->willReturn('fence-token');
        }
        if ($verifier === null) {
            $verifier = $this->createMock(DependencyUsageVerifierInterface::class);
            $verifier->method('verdict')->willReturn(DependencyUsageVerdict::Safe);
        }

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

        return new class ($foldersById, $deleted, $rows, $referencedIds, $connection, $authorization, $loopGuard, $workspaceScope, $fence, $verifier, $dispatcher ?? new EventDispatcher()) extends EmptyFolderSweepService {
            /**
             * @param array<int, ?Asset\Folder>               $foldersById
             * @param \ArrayObject<int, int>                  $deleted
             * @param list<array{id: int, full_path: string}> $rows
             * @param list<int>                               $referencedIds
             */
            public function __construct(private readonly array $foldersById, private readonly \ArrayObject $deleted, private readonly array $rows, private readonly array $referencedIds, Connection $connection, ElementAuthorization $authorization, LoopGuard $loopGuard, AssetWorkspaceQueryScope $workspaceScope, AssetDeletionFenceInterface $fence, DependencyUsageVerifierInterface $verifier, EventDispatcher $dispatcher)
            {
                parent::__construct($connection, new NullLogger(), $authorization, $loopGuard, new ReviewedAssetLockCoordinator($loopGuard), $workspaceScope, $fence, $verifier, $dispatcher);
            }

            protected function loadFolder(int $id): ?Asset\Folder
            {
                return $this->foldersById[$id] ?? null;
            }

            protected function isReferenced(int $folderId): bool
            {
                return in_array($folderId, $this->referencedIds, true);
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
    public function dispatchesATypedEmptyFolderDeletedEventOnEachDeletion(): void
    {
        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::EMPTY_FOLDER_DELETED, static function (AssetMutationEvent $event) use (&$received): void {
            $received[] = $event;
        });
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], new \ArrayObject(), dispatcher: $dispatcher);

        $this->apply($service, [5]);

        self::assertCount(1, $received, 'a deleted empty folder dispatches exactly one typed outcome');
        self::assertSame([5], $received[0]->assetIds);
        self::assertSame('empty_folder_deleted', $received[0]->mutation);
        self::assertArrayHasKey('path', $received[0]->context);
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
    public function skipsALockedFolder(): void
    {
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true, locked: true)], $deleted);
        $result = $this->apply($service, [5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function skipsAFolderReferencedByALiveDependency(): void
    {
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted, referencedIds: [5]);
        $result = $this->apply($service, [5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function previewMarksAReferencedFolderNotEligible(): void
    {
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], referencedIds: [5]);
        $result = $service->previewDelete([5]);

        self::assertSame(0, $result['eligible']);
        self::assertSame(1, $result['skipped']);
    }

    #[Test]
    public function skipsAFolderWhileAConcurrentSaveLeavesTheProjectionDirty(): void
    {
        $verifier = $this->createMock(DependencyUsageVerifierInterface::class);
        $verifier->method('verdict')->willReturn(DependencyUsageVerdict::Unknown);
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted, verifier: $verifier);

        $result = $this->apply($service, [5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function previewMarksAFolderWithADirtyProjectionNotEligible(): void
    {
        $verifier = $this->createMock(DependencyUsageVerifierInterface::class);
        $verifier->method('verdict')->willReturn(DependencyUsageVerdict::Unknown);
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], verifier: $verifier);

        $result = $service->previewDelete([5]);

        self::assertSame(0, $result['eligible']);
        self::assertSame(1, $result['skipped']);
    }

    #[Test]
    public function skipsWhenTheDatabaseRecheckFindsANewChild(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, parentId INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE dependencies (id INTEGER PRIMARY KEY, targetid INTEGER NOT NULL, targettype TEXT NOT NULL)');
        $connection->insert('assets', ['id' => 9, 'parentId' => 5]);
        $folder = $this->folder(5, hasChildren: false, allowed: true);
        $deleted = new \ArrayObject();
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $authorization->method('isAllowed')->willReturn(true);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn('fence-token');
        $verifier = $this->createMock(DependencyUsageVerifierInterface::class);
        $verifier->method('verdict')->willReturn(DependencyUsageVerdict::Safe);
        $workspaceScope = new AssetWorkspaceQueryScope(
            $connection,
            $authorization,
            $this->createMock(ActorContextProvider::class),
        );
        $service = new class ($connection, $folder, $deleted, $authorization, $loopGuard, $workspaceScope, $fence, $verifier) extends EmptyFolderSweepService {
            public function __construct(Connection $connection, private readonly Asset\Folder $folder, private readonly \ArrayObject $deleted, ElementAuthorization $authorization, LoopGuard $loopGuard, AssetWorkspaceQueryScope $workspaceScope, AssetDeletionFenceInterface $fence, DependencyUsageVerifierInterface $verifier)
            {
                parent::__construct($connection, new NullLogger(), $authorization, $loopGuard, new ReviewedAssetLockCoordinator($loopGuard), $workspaceScope, $fence, $verifier, new EventDispatcher());
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
    public function skipsAChildlessFolderStillReferencedByADependencyRow(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, parentId INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE dependencies (id INTEGER PRIMARY KEY, targetid INTEGER NOT NULL, targettype TEXT NOT NULL)');
        $connection->insert('dependencies', ['id' => 1, 'targetid' => 5, 'targettype' => 'asset']);
        $folder = $this->folder(5, hasChildren: false, allowed: true);
        $deleted = new \ArrayObject();
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $authorization->method('isAllowed')->willReturn(true);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn('fence-token');
        $verifier = $this->createMock(DependencyUsageVerifierInterface::class);
        $verifier->method('verdict')->willReturn(DependencyUsageVerdict::Safe);
        $workspaceScope = new AssetWorkspaceQueryScope(
            $connection,
            $authorization,
            $this->createMock(ActorContextProvider::class),
        );
        $service = new class ($connection, $folder, $deleted, $authorization, $loopGuard, $workspaceScope, $fence, $verifier) extends EmptyFolderSweepService {
            public function __construct(Connection $connection, private readonly Asset\Folder $folder, private readonly \ArrayObject $deleted, ElementAuthorization $authorization, LoopGuard $loopGuard, AssetWorkspaceQueryScope $workspaceScope, AssetDeletionFenceInterface $fence, DependencyUsageVerifierInterface $verifier)
            {
                parent::__construct($connection, new NullLogger(), $authorization, $loopGuard, new ReviewedAssetLockCoordinator($loopGuard), $workspaceScope, $fence, $verifier, new EventDispatcher());
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
    public function acquiresRefreshesAndReleasesTheDeletionFenceOnASuccessfulSweep(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->expects(self::once())->method('acquire')->with(5, 'empty_folder_sweep')->willReturn('token');
        $fence->expects(self::once())->method('refreshOrFail')->with(5, 'token');
        $fence->expects(self::once())->method('release')->with(5, 'token');
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted, fence: $fence);

        $result = $this->apply($service, [5]);

        self::assertSame(1, $result['deleted']);
        self::assertSame([5], $deleted->getArrayCopy());
    }

    #[Test]
    public function failsAFolderAnotherOperationIsAlreadyDeleting(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn(null);
        $fence->expects(self::never())->method('release');
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted, fence: $fence);

        $result = $this->apply($service, [5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertArrayHasKey(5, $result['errors']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function failsAndReleasesWhenTheFenceIsLostBeforeDeletion(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn('token');
        $fence->method('refreshOrFail')->willThrowException(new AssetDeletionFenceLostException(5));
        $fence->expects(self::once())->method('release')->with(5, 'token');
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted, fence: $fence);

        $result = $this->apply($service, [5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function stillReportsSuccessWhenTheFenceReleaseThrows(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn('token');
        $fence->method('release')->willThrowException(new \RuntimeException('release failed'));
        $deleted = new \ArrayObject();
        $service = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted, fence: $fence);

        $result = $this->apply($service, [5]);

        self::assertSame(1, $result['deleted']);
        self::assertSame(0, $result['failed']);
        self::assertSame([5], $deleted->getArrayCopy());
    }

    #[Test]
    public function theSweepFenceBlocksAConcurrentDependencyWriterAcrossConnections(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-pilot-sweep-fence-');
        self::assertIsString($path);
        try {
            $deleterConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            $schema = new Schema();
            DependencyProjectionSchema::ensure($schema);
            foreach ($schema->toSql($deleterConnection->getDatabasePlatform()) as $sql) {
                $deleterConnection->executeStatement($sql);
            }
            $deleterConnection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT)');
            $deleterConnection->insert('assets', ['id' => 5, 'path' => '/x/', 'filename' => 'folder5', 'type' => 'folder']);

            $writerConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            $deleterFence = new DbalAssetDeletionFence($deleterConnection);
            $writerFence = new DbalAssetDeletionFence($writerConnection);

            // The sweep acquires the deletion fence for folder 5, exactly as EmptyFolderSweepService now does.
            $token = $deleterFence->acquire(5, 'empty_folder_sweep');
            self::assertNotNull($token);

            // A save on a second connection that would reference folder 5 is blocked while the sweep holds the fence.
            try {
                $writerFence->assertWritableTargets([5]);
                self::fail('Expected the fenced folder to block the concurrent dependency writer.');
            } catch (ValidationException $e) {
                self::assertStringContainsString('being deleted', $e->getMessage());
            }

            // Once the sweep releases the fence, the writer may proceed.
            $deleterFence->release(5, $token);
            $writerFence->assertWritableTargets([5]);
        } finally {
            @unlink($path);
        }
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
