<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifierInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\AuthorizedAssetPage;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinder;
use Oronts\AssetPilotBundle\Tests\Unit\Support\MutationSafetyDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\User;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(UnusedAssetFinder::class)]
class UnusedAssetFinderTest extends TestCase
{
    use MutationSafetyDependencies;

    private function finder(): UnusedAssetFinder
    {
        $connection = $this->createMock(Connection::class);

        return new UnusedAssetFinder(
            $connection,
            new NullLogger(),
            $this->createMock(ConfidenceScorer::class),
            new EventDispatcher(),
            ...$this->mutationSafetyDependencies($connection),
        );
    }

    #[Test]
    public function hydrateRowsSerializesAssetTimestampsAsRfc3339Utc(): void
    {
        $connection = $this->createMock(Connection::class);
        $scorer = $this->createMock(ConfidenceScorer::class);
        $scorer->method('score')->willReturnArgument(0);
        $finder = new UnusedAssetFinder($connection, new NullLogger(), $scorer, new EventDispatcher(), ...$this->mutationSafetyDependencies($connection));

        $method = new \ReflectionMethod(UnusedAssetFinder::class, 'hydrateRows');
        $rows = $method->invoke($finder, [
            ['id' => 5, 'created_at' => 0, 'modified_at' => 2, 'path' => '/x/', 'filename' => 'a.png'],
        ]);

        self::assertSame('1970-01-01T00:00:02+00:00', $rows[0]['modified_at'], 'modificationDate unix 2 must serialize as RFC 3339 UTC');
        self::assertNull($rows[0]['created_at'], 'a zero creationDate stays null rather than serializing the epoch');
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
    public function invalidDateFiltersAreRejectedInsteadOfWideningTheQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder()->findUnused(['before' => 'definitely-not-a-date']);
    }

    #[Test]
    public function invalidConfidenceIsRejectedInsteadOfWideningTheQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid confidence filter.');

        $this->finder()->findUnused(['confidence' => 'protectd']);
    }

    #[Test]
    public function scopedUserGetsNativelyAuthorizedUnusedRowsWithoutALeakingTotal(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT, mimetype TEXT, creationDate INTEGER, modificationDate INTEGER)');
        $connection->executeStatement('CREATE TABLE dependencies (targetid INTEGER, targettype TEXT)');
        $connection->executeStatement('CREATE TABLE properties (cid INTEGER, ctype TEXT, name TEXT, data TEXT)');
        $connection->executeStatement('CREATE TABLE asset_pilot_checksum (asset_id INTEGER, file_size INTEGER, size_known INTEGER, indexed_at TEXT)');
        $connection->executeStatement('CREATE TABLE users_workspaces_asset (userId INTEGER, cpath TEXT, view INTEGER)');
        foreach ([1, 2, 3] as $id) {
            $connection->insert('assets', ['id' => $id, 'path' => '/p/', 'filename' => $id . '.jpg', 'type' => 'image', 'mimetype' => 'image/jpeg', 'creationDate' => 1, 'modificationDate' => $id]);
        }
        $connection->insert('users_workspaces_asset', ['userId' => 42, 'cpath' => '/p', 'view' => 1]);
        $connection->insert('users_workspaces_asset', ['userId' => 42, 'cpath' => '/p/2.jpg', 'view' => 0]);

        $scorer = $this->createMock(ConfidenceScorer::class);
        $scorer->method('score')->willReturnCallback(static fn (array $rows): array => $rows);
        $user = (new User())
            ->setId(42)
            ->setRoles([])
            ->setAdmin(false)
            ->setPermission('assets', true);
        $actors = $this->createMock(ActorContextProvider::class);
        $actors->method('resolveUser')->willReturn($user);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(42));
        $authorization->method('isAllowed')->willReturn(true);
        $scope = new AssetWorkspaceQueryScope($connection, $authorization, $actors);
        $dependencies = $this->mutationSafetyDependencies($connection, authorization: $authorization, workspaceScope: $scope);
        $stubs = [];
        foreach ([1, 2, 3] as $id) {
            $stub = $this->createStub(Asset::class);
            $stub->method('getId')->willReturn($id);
            $stubs[$id] = $stub;
        }
        $dependencies[6] = new AuthorizedAssetPage($authorization, $scope, static fn (int $id): ?Asset => $stubs[$id] ?? null);
        $finder = new class ($connection, new NullLogger(), $scorer, new EventDispatcher(), $dependencies) extends UnusedAssetFinder {
            public function __construct(Connection $connection, NullLogger $logger, ConfidenceScorer $scorer, EventDispatcher $dispatcher, array $dependencies)
            {
                parent::__construct($connection, $logger, $scorer, $dispatcher, ...$dependencies);
            }

            protected function fileSize(string $fullPath): int
            {
                return 0;
            }
        };

        $result = $finder->findUnused(page: 2, limit: 1, sort: 'id', order: 'asc');

        self::assertNull($result['total'], 'a scoped user must not receive an SQL-count-derived total');
        self::assertNull($result['pages']);
        self::assertFalse($result['hasMore'], 'only assets 1 and 3 are workspace-visible, so page 2 (id 3) is the last');
        self::assertSame(3, $result['items'][0]['id']);
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

    #[Test]
    public function aggregateStatsConsumesAStreamingResultOnce(): void
    {
        $rows = (static function (): \Generator {
            yield ['id' => 1, 'type' => 'image', 'path' => '/p/', 'filename' => 'a.jpg'];
            yield ['id' => 2, 'type' => 'document', 'path' => '/p/', 'filename' => 'b.pdf'];
        })();

        $stats = $this->finderWithSizes(['/p/a.jpg' => 10, '/p/b.pdf' => 20])->aggregate($rows);

        self::assertSame(2, $stats['totalCount']);
        self::assertSame(30, $stats['totalSize']);
    }

    #[Test]
    public function aggregateStatsCountsUnknownSizesWithoutTreatingThemAsZero(): void
    {
        $stats = $this->finderWithSizes(['/p/empty.jpg' => 0, '/p/unavailable.jpg' => null])->aggregate([
            ['id' => 1, 'type' => 'image', 'path' => '/p/', 'filename' => 'empty.jpg'],
            ['id' => 2, 'type' => 'image', 'path' => '/p/', 'filename' => 'unavailable.jpg'],
        ]);

        self::assertSame(0, $stats['totalSize']);
        self::assertSame(1, $stats['unknownSizeCount']);
        self::assertSame(1, $stats['byType'][0]['unknown_size_count']);
    }

    /** @param array<string, int|null> $sizes keyed by full path */
    private function finderWithSizes(array $sizes): object
    {
        $connection = $this->createMock(Connection::class);

        return new class ($connection, new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), $this->mutationSafetyDependencies($connection), $sizes) extends UnusedAssetFinder {
            /** @param array<string, int|null> $sizes */
            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, array $dependencies, private array $sizes)
            {
                parent::__construct($c, $l, $s, $d, ...$dependencies);
            }

            protected function fileSize(string $fullPath): ?int
            {
                return $this->sizes[$fullPath] ?? null;
            }

            /** @param iterable<array{id: mixed, type: mixed, path: mixed, filename: mixed}> $rows */
            public function aggregate(iterable $rows): array
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
    public function deleteObserverFailureDoesNotChangeTheCommittedDeleteAndLaterObserversRun(): void
    {
        $dispatcher = new EventDispatcher();
        $laterObserverCalled = false;
        $dispatcher->addListener(AssetPilotEvents::UNUSED_DELETED, static fn (): never => throw new \RuntimeException('Observer failed.'), 10);
        $dispatcher->addListener(AssetPilotEvents::UNUSED_DELETED, static function () use (&$laterObserverCalled): void {
            $laterObserverCalled = true;
        });

        $result = $this->moveFinder(
            [1 => $this->asset(true)],
            referenced: false,
            eventDispatcher: $dispatcher,
        )->deleteAssets([1]);

        self::assertTrue($laterObserverCalled);
        self::assertSame(1, $result['deleted']);
        self::assertSame(0, $result['failed']);
        self::assertSame(['Unused-delete observer delivery failed.'], $result['observerWarnings']);
    }

    #[Test]
    public function moveObserverFailureDoesNotChangeTheCommittedMoveAndLaterObserversRun(): void
    {
        $dispatcher = new EventDispatcher();
        $laterObserverCalled = false;
        $dispatcher->addListener(AssetPilotEvents::UNUSED_MOVED, static fn (): never => throw new \RuntimeException('Observer failed.'), 10);
        $dispatcher->addListener(AssetPilotEvents::UNUSED_MOVED, static function () use (&$laterObserverCalled): void {
            $laterObserverCalled = true;
        });

        $result = $this->moveFinder(
            [1 => $this->asset(true)],
            referenced: false,
            eventDispatcher: $dispatcher,
        )->moveAssets([1], '/Archive');

        self::assertTrue($laterObserverCalled);
        self::assertSame(1, $result['moved']);
        self::assertSame(0, $result['failed']);
        self::assertSame(['Unused-move observer delivery failed.'], $result['observerWarnings']);
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
    public function mutationsFailClosedWithoutContentReferenceEvidence(): void
    {
        $finder = $this->moveFinder([1 => $this->asset(true)], referenced: false, contentEvidence: false);

        $deleted = $finder->deleteAssets([1]);
        $moved = $finder->moveAssets([1], '/Archive');

        self::assertSame(0, $deleted['deleted']);
        self::assertStringContainsString('verification is not configured', $deleted['errors'][1]);
        self::assertSame(0, $moved['moved']);
        self::assertStringContainsString('verification is not configured', $moved['errors'][-1]);
    }

    #[Test]
    public function deleteRequiresDefinitelyUnusedConfidence(): void
    {
        $result = $this->moveFinder(
            [1 => $this->asset(true)],
            referenced: false,
            deletionConfident: false,
        )->deleteAssets([1]);

        self::assertSame(0, $result['deleted']);
        self::assertStringContainsString('confidence threshold', $result['errors'][1]);
    }

    #[Test]
    public function mutationSkipsWhenAnotherWorkerHoldsTheAssetLock(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireAsset(1));

        $result = $this->moveFinder(
            [1 => $this->asset(true)],
            referenced: false,
            loopGuard: $worker,
        )->deleteAssets([1]);

        self::assertSame(0, $result['deleted']);
        self::assertSame('Asset is being processed by another job', $result['errors'][1]);
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
        $scanner->method('freshlyReferencedInContent')->willReturn(true);

        $result = $this->moveFinder([1 => $this->asset(true)], referenced: false, scanner: $scanner)->deleteAssets([1]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('content', $result['errors'][1]);
    }

    #[Test]
    public function moveAssetsSkipsAnAssetReferencedInContent(): void
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('freshlyReferencedInContent')->willReturn(true);

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

    #[Test]
    public function deleteRevalidatesThePreviewFingerprintWhileTheAssetLockIsHeld(): void
    {
        $locked = false;
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('acquireAsset')->with(1)->willReturnCallback(function () use (&$locked): bool {
            $locked = true;

            return true;
        });
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);
        $fingerprints = $this->createMock(AssetMutationFingerprintService::class);
        $fingerprints->expects(self::once())
            ->method('assertUnchanged')
            ->with(1, ['asset:1' => 'preview-fingerprint'])
            ->willReturnCallback(function () use (&$locked): never {
                self::assertTrue($locked);
                throw new StaleApplyPlanException('changed');
            });
        $asset = $this->asset(true);
        $asset->expects(self::never())->method('delete');

        $this->expectException(StaleApplyPlanException::class);
        $this->moveFinder(
            [1 => $asset],
            referenced: false,
            loopGuard: $loopGuard,
            mutationFingerprints: $fingerprints,
        )->deleteAssets([1], ['asset:1' => 'preview-fingerprint']);
    }

    #[Test]
    public function staleLaterPlanTargetPreventsEveryEarlierDeleteAndReleasesLocksInReverseOrder(): void
    {
        $acquired = [];
        $released = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturnCallback(static function (int $id) use (&$acquired): bool {
            $acquired[] = $id;

            return true;
        });
        $loopGuard->method('releaseAsset')->willReturnCallback(static function (int $id) use (&$released): void {
            $released[] = $id;
        });
        $fingerprints = $this->createMock(AssetMutationFingerprintService::class);
        $fingerprints->method('assertUnchanged')->willReturnCallback(static function (int $id): void {
            if ($id === 2) {
                throw new StaleApplyPlanException('changed');
            }
        });
        $first = $this->asset(true);
        $first->expects(self::never())->method('delete');

        try {
            $this->moveFinder(
                [1 => $first, 2 => $this->asset(true)],
                referenced: false,
                loopGuard: $loopGuard,
                mutationFingerprints: $fingerprints,
            )->deleteAssets([2, 1], ['asset:1' => 'one', 'asset:2' => 'two']);
            self::fail('Expected the stale plan to be rejected.');
        } catch (StaleApplyPlanException) {
        }

        self::assertSame([1, 2], $acquired);
        self::assertSame([2, 1], $released);
    }

    /**
     * @param array<int, Asset> $assetsById
     */
    private function moveFinder(
        array $assetsById,
        bool $referenced,
        bool $folderAllowed = true,
        ?ContentUsageScanner $scanner = null,
        bool $contentEvidence = true,
        ?LoopGuard $loopGuard = null,
        bool $deletionConfident = true,
        ?EventDispatcher $eventDispatcher = null,
        ?AssetMutationFingerprintService $mutationFingerprints = null,
    ): UnusedAssetFinder {
        foreach ($assetsById as $assetId => $asset) {
            $asset->method('getId')->willReturn((int) $assetId);
        }
        $folder = $this->createMock(Asset\Folder::class);
        $folder->method('isAllowed')->willReturn($folderAllowed);
        if ($contentEvidence) {
            $scanner ??= $this->createMock(ContentUsageScanner::class);
            $scanner->method('canVerify')->willReturn(true);
        }

        $dependencyVerifier = $this->createMock(DependencyUsageVerifierInterface::class);
        $dependencyVerifier->method('verdict')->willReturn(DependencyUsageVerdict::Safe);

        $connection = $this->createMock(Connection::class);
        $dependencies = $this->mutationSafetyDependencies(
            $connection,
            contentScanner: $scanner,
            loopGuard: $loopGuard,
            dependencyVerifier: $dependencyVerifier,
            fingerprints: $mutationFingerprints,
            contentEvidence: $contentEvidence,
        );

        return new class ($connection, new NullLogger(), $this->createMock(ConfidenceScorer::class), $eventDispatcher ?? new EventDispatcher(), $assetsById, $referenced, $folder, $deletionConfident, $dependencies) extends UnusedAssetFinder {
            /** @param array<int, Asset> $assetsById */
            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, private array $assetsById, private bool $referenced, private Asset\Folder $folder, private bool $deletionConfident, array $dependencies)
            {
                parent::__construct($c, $l, $s, $d, ...$dependencies);
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

            protected function hasDeletionConfidence(int $assetId): bool
            {
                return $this->deletionConfident;
            }

            protected function assetAtPath(string $path): ?Asset
            {
                return null;
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

        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('canVerify')->willReturn(true);
        $dependencyVerifier = $this->createMock(DependencyUsageVerifierInterface::class);
        $dependencyVerifier->method('verdict')->willReturn(DependencyUsageVerdict::Safe);
        $connection = $this->createMock(Connection::class);
        $dependencies = $this->mutationSafetyDependencies($connection, contentScanner: $scanner, dependencyVerifier: $dependencyVerifier);
        $finder = new class ($connection, new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), new StatsCache(new ArrayAdapter()), $asset, $dependencies) extends UnusedAssetFinder {
            public int $computeCalls = 0;

            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, StatsCache $cache, private readonly Asset $asset, array $dependencies)
            {
                parent::__construct($c, $l, $s, $d, ...$dependencies, statsCache: $cache, statsTtl: 60);
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

            protected function hasDeletionConfidence(int $assetId): bool
            {
                return true;
            }
        };

        $finder->getUnusedStatsCached();
        $finder->deleteAssets([1]);
        $finder->getUnusedStatsCached();

        self::assertSame(2, $finder->computeCalls, 'a delete evicts the stale stats so the next read recomputes');
    }

    private function cachingFinder(int $ttl): UnusedAssetFinder
    {
        $connection = $this->createMock(Connection::class);

        return new class ($connection, new NullLogger(), $this->createMock(ConfidenceScorer::class), new EventDispatcher(), new StatsCache(new ArrayAdapter()), $ttl, $this->mutationSafetyDependencies($connection)) extends UnusedAssetFinder {
            public int $computeCalls = 0;

            public function __construct(Connection $c, NullLogger $l, ConfidenceScorer $s, EventDispatcher $d, StatsCache $cache, int $ttl, array $dependencies)
            {
                parent::__construct($c, $l, $s, $d, ...$dependencies, statsCache: $cache, statsTtl: $ttl);
            }

            public function getUnusedStats(): array
            {
                ++$this->computeCalls;

                return ['totalCount' => $this->computeCalls, 'totalSize' => 0, 'totalSizeFormatted' => '0 B', 'byType' => []];
            }
        };
    }
}
