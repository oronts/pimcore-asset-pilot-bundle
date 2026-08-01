<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Enum\QuarantineStatus;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifierInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\AuthorizedAssetPage;
use Oronts\AssetPilotBundle\Service\ReviewedAssetLockCoordinator;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use Oronts\AssetPilotBundle\Tests\Unit\Support\MutationSafetyDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\User;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(QuarantineService::class)]
class QuarantineServiceTest extends TestCase
{
    use MutationSafetyDependencies;

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
        bool $contentEvidence = true,
        ?AssetMutationFingerprintService $mutationFingerprints = null,
        array $recordStatuses = [],
    ): object {
        foreach ($expiredIds as $assetId) {
            $originalPaths[$assetId] ??= '/Original/' . $assetId;
        }

        foreach ($assetsById as $assetId => $asset) {
            if ($asset !== null) {
                $asset->method('getId')->willReturn((int) $assetId);
            }
        }

        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('isReferenced')->willReturn($isReferenced);
        if ($loopGuard === null) {
            $loopGuard = $this->createMock(LoopGuard::class);
            $loopGuard->method('acquireAsset')->willReturn(true);
            $loopGuard->method('acquireTarget')->willReturn(true);
        }
        if ($contentEvidence) {
            $contentScanner ??= $this->createMock(ContentUsageScanner::class);
            $contentScanner->method('canVerify')->willReturn(true);
        }
        $dependencyVerifier = $this->createMock(DependencyUsageVerifierInterface::class);
        $dependencyVerifier->method('verdict')->willReturn(DependencyUsageVerdict::Safe);
        $connection = $this->createMock(Connection::class);
        [$contentScanner, , $reviewedLocks, $authorization, $dependencyVerifier, $workspaceScope, $authorizedPage, $mutationFingerprints, , $deletionFence] = $this->mutationSafetyDependencies(
            $connection,
            contentScanner: $contentScanner,
            loopGuard: $loopGuard,
            dependencyVerifier: $dependencyVerifier,
            fingerprints: $mutationFingerprints,
            contentEvidence: $contentEvidence,
        );

        return new class (
            $connection,
            $loopGuard,
            $finder,
            new EventDispatcher(),
            new NullLogger(),
            $assetsById,
            $originalPaths,
            $recordStatuses,
            $allowCreate,
            $expiredIds,
            $contentScanner,
            $assetsAtPath,
            $authorization,
            $dependencyVerifier,
            $workspaceScope,
            $authorizedPage,
            $mutationFingerprints,
            $deletionFence,
        ) extends QuarantineService {
            public array $recorded = [];
            public array $removed = [];
            public array $deleted = [];
            public array $committed = [];
            public array $pending = [];

            /** @param array<int, ?Asset> $assetsById @param array<int, ?string> $originalPaths @param array<int, QuarantineStatus> $recordStatuses @param list<int> $expiredIds */
            public function __construct(Connection $c, LoopGuard $lg, UnusedAssetFinderInterface $f, $ed, $log, private array $assetsById, private array $originalPaths, private array $recordStatuses, private bool $allowCreate, private array $expiredIds, ContentUsageScanner $scanner, private array $assetsAtPath, ElementAuthorization $authorization, DependencyUsageVerifierInterface $dependencyVerifier, AssetWorkspaceQueryScope $workspaceScope, AuthorizedAssetPage $authorizedPage, AssetMutationFingerprintService $mutationFingerprints, AssetDeletionFenceInterface $deletionFence)
            {
                parent::__construct($c, $lg, new ReviewedAssetLockCoordinator($lg), $f, $ed, $log, $scanner, $authorization, $dependencyVerifier, $workspaceScope, $authorizedPage, $mutationFingerprints, new LoopGuardedAssetSaver($lg), $deletionFence);
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

            protected function createPendingQuarantineRecord(int $assetId, string $originalPath): void
            {
                $this->recorded[$assetId] = $originalPath;
                $this->originalPaths[$assetId] = $originalPath;
                $this->recordStatuses[$assetId] = QuarantineStatus::Pending;
            }

            protected function markQuarantinePending(int $assetId): void
            {
                if (!array_key_exists($assetId, $this->originalPaths)) {
                    throw new \RuntimeException('missing quarantine record');
                }
                $this->recordStatuses[$assetId] = QuarantineStatus::Pending;
                $this->pending[] = $assetId;
            }

            protected function markQuarantineCommitted(int $assetId): void
            {
                if (!array_key_exists($assetId, $this->originalPaths)) {
                    throw new \RuntimeException('missing quarantine record');
                }
                $this->recordStatuses[$assetId] = QuarantineStatus::Committed;
                $this->committed[] = $assetId;
            }

            protected function findQuarantineRecord(int $assetId): ?array
            {
                $path = $this->originalPaths[$assetId] ?? null;
                if ($path === null) {
                    return null;
                }

                return [
                    'originalPath' => $path,
                    'status' => $this->recordStatuses[$assetId] ?? QuarantineStatus::Committed,
                ];
            }

            public function findOriginalPathForTest(int $assetId): ?string
            {
                return $this->findQuarantineRecord($assetId)['originalPath'] ?? null;
            }

            protected function assetAtPath(string $path): ?Asset
            {
                return $this->assetsAtPath[$path] ?? null;
            }

            protected function deleteQuarantineRecord(int $assetId): int
            {
                $existed = array_key_exists($assetId, $this->originalPaths) || array_key_exists($assetId, $this->recordStatuses);
                unset($this->originalPaths[$assetId], $this->recordStatuses[$assetId]);
                $this->removed[] = $assetId;

                return $existed ? 1 : 0;
            }
        };
    }
    private function asset(string $path, bool $allowed = true, bool $locked = false): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('isAllowed')->willReturn($allowed);
        $asset->method('hasProperty')->willReturn($locked);
        $asset->method('getProperty')->willReturn($locked);

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
    public function quarantineRevalidatesThePreviewFingerprintWhileTheAssetLockIsHeld(): void
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

        $this->expectException(StaleApplyPlanException::class);
        $this->service(
            [1 => $this->asset('/Products/a.jpg')],
            loopGuard: $loopGuard,
            mutationFingerprints: $fingerprints,
        )->quarantine([1], ['asset:1' => 'preview-fingerprint']);
    }

    #[Test]
    public function staleLaterPlanTargetPreventsEveryEarlierQuarantineAndReleasesLocksInReverseOrder(): void
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
        $first = $this->asset('/Products/a.jpg');
        $first->expects(self::never())->method('save');

        try {
            $this->service(
                [1 => $first, 2 => $this->asset('/Products/b.jpg')],
                loopGuard: $loopGuard,
                mutationFingerprints: $fingerprints,
            )->quarantine([2, 1], ['asset:1' => 'one', 'asset:2' => 'two']);
            self::fail('Expected the stale plan to be rejected.');
        } catch (StaleApplyPlanException) {
        }

        self::assertSame([1, 2], $acquired);
        self::assertSame([2, 1], $released);
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
    public function repeatedQuarantinePreservesTheFirstOrigin(): void
    {
        $service = $this->service(
            [1 => $this->asset('/Quarantine/a.jpg')],
            [1 => '/Products/a.jpg'],
        );

        $result = $service->quarantine([1]);

        self::assertSame(1, $result['quarantined']);
        self::assertArrayNotHasKey(1, $service->recorded);
        self::assertSame('/Products/a.jpg', $service->findOriginalPathForTest(1));
    }

    #[Test]
    public function quarantineCommitsAPendingRecordAfterTheMoveSurvivedAProcessCrash(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn('/Quarantine/a.jpg');
        $asset->method('isAllowed')->willReturn(true);
        $asset->expects(self::never())->method('save');
        $service = $this->service(
            [1 => $asset],
            [1 => '/Products/a.jpg'],
            recordStatuses: [1 => QuarantineStatus::Pending],
        );

        $result = $service->quarantine([1]);

        self::assertSame(1, $result['quarantined']);
        self::assertSame([1], $service->committed);
        self::assertSame('/Products/a.jpg', $service->findOriginalPathForTest(1));
    }

    #[Test]
    public function quarantineRetriesAPendingMoveWithoutReplacingTheOriginalPath(): void
    {
        $service = $this->service(
            [1 => $this->asset('/Moved/a.jpg')],
            [1 => '/Products/a.jpg'],
            recordStatuses: [1 => QuarantineStatus::Pending],
        );

        $result = $service->quarantine([1]);

        self::assertSame(1, $result['quarantined']);
        self::assertSame([], $service->recorded);
        self::assertSame([1], $service->committed);
        self::assertSame('/Products/a.jpg', $service->findOriginalPathForTest(1));
    }

    #[Test]
    public function quarantineFailsClosedWhenAnExistingOriginRecordIsMissing(): void
    {
        $service = $this->service([1 => $this->asset('/Quarantine/a.jpg')]);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertSame('Failed to quarantine the asset.', $result['errors'][1]);
    }

    #[Test]
    public function quarantineFailsClosedWithoutContentReferenceEvidence(): void
    {
        $result = $this->service(
            [1 => $this->asset('/Products/a.jpg')],
            contentEvidence: false,
        )->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('verification is not configured', $result['errors'][1]);
    }

    #[Test]
    public function quarantineSkipsWhenTheTargetPathLockIsUnavailable(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(false);
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);
        $loopGuard->expects(self::never())->method('releaseTarget');

        $result = $this->service([1 => $this->asset('/Products/a.jpg')], loopGuard: $loopGuard)->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function quarantineLeavesAPendingOriginRecordWhenTheMoveFails(): void
    {
        $asset = $this->asset('/Products/a.jpg');
        $asset->method('save')->willThrowException(new \RuntimeException('storage unavailable'));
        $service = $this->service([1 => $asset]);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertSame('/Products/a.jpg', $service->recorded[1]);
        self::assertSame([], $service->removed);
        self::assertSame([], $service->committed);
    }

    #[Test]
    public function skipsAssetsReferencedInContent(): void
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('freshlyReferencedInContent')->willReturn(true);

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
        $scanner->method('freshlyReferencedInContent')->willReturn(true);

        $service = $this->service([1 => $this->asset('/Quarantine/a.jpg')], expiredIds: [1], contentScanner: $scanner);

        $result = $service->purgeExpired();

        self::assertSame(0, $result['purged']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $service->deleted);
    }

    #[Test]
    public function purgeFailsClosedWithoutContentReferenceEvidence(): void
    {
        $service = $this->service(
            [1 => $this->asset('/Quarantine/a.jpg')],
            expiredIds: [1],
            contentEvidence: false,
        );

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
    public function restoreRefusesAProtectedAsset(): void
    {
        $service = $this->service([5 => $this->asset('/Quarantine/a.jpg', locked: true)], [5 => '/Products/a.jpg']);

        self::assertFalse($service->restore(5));
        self::assertSame([], $service->removed);
    }


    #[Test]
    public function restoreRecoversAPendingRecordForAnAlreadyMovedAsset(): void
    {
        $service = $this->service(
            [5 => $this->asset('/Quarantine/a.jpg')],
            [5 => '/Products/a.jpg'],
            recordStatuses: [5 => QuarantineStatus::Pending],
        );

        self::assertTrue($service->restore(5));
        self::assertSame([5], $service->committed);
        self::assertSame([5], $service->removed);
    }

    #[Test]
    public function recoverQuarantineCommitsAnInterruptedPendingMove(): void
    {
        $service = $this->service(
            [5 => $this->asset('/Quarantine/a.jpg')],
            [5 => '/Products/a.jpg'],
            recordStatuses: [5 => QuarantineStatus::Pending],
        );

        self::assertTrue($service->recoverQuarantine(5));
        self::assertSame([5], $service->committed);
    }

    #[Test]
    public function recoverQuarantineRefusesATamperedRecordOutsideTheQuarantineRoot(): void
    {
        $service = $this->service(
            [5 => $this->asset('/Products/a.jpg')],
            [5 => '/Original/a.jpg'],
            recordStatuses: [5 => QuarantineStatus::Committed],
        );

        self::assertFalse($service->recoverQuarantine(5));
        self::assertSame([], $service->committed);
    }

    #[Test]
    public function recoverQuarantineReportsNotRecoveredWhenTheCopyNeverReachedQuarantine(): void
    {
        // A merge disposition crashed after inserting the pending record but before moving the copy into
        // quarantine, so the copy is still live at its original path. Recovery must report not-recovered so the
        // disposition re-runs; it must NOT finalize it as a completed restore (which would drop the record and
        // fire a spurious RESTORED event while leaving the duplicate copy live). Restore-crash reconciliation of
        // an already-moved-back asset belongs to restore(), covered separately below.
        $service = $this->service(
            [5 => $this->asset('/Products/a.jpg')],
            [5 => '/Products/a.jpg'],
            recordStatuses: [5 => QuarantineStatus::Pending],
        );

        self::assertFalse($service->recoverQuarantine(5));
        self::assertSame([], $service->removed, 'the pending record is kept so the disposition re-runs');
    }

    #[Test]
    public function restoreIsIdempotentAndFinalizesTheRecordWhenTheAssetWasAlreadyRestored(): void
    {
        $service = $this->service(
            [5 => $this->asset('/Products/a.jpg')],
            [5 => '/Products/a.jpg'],
            recordStatuses: [5 => QuarantineStatus::Committed],
        );

        self::assertTrue($service->restore(5), 'a retry after a partial restore reports success');
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
    public function purgeSkipsAPendingRecordEvenWhenItIsExpired(): void
    {
        $service = $this->service(
            [1 => $this->asset('/Quarantine/a.jpg')],
            expiredIds: [1],
            recordStatuses: [1 => QuarantineStatus::Pending],
        );

        $result = $service->purgeExpired(30);

        self::assertSame(0, $result['purged']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $service->deleted);
    }

    #[Test]
    public function purgeSkipsACommittedRecordWhenTheAssetWasMovedOutOfQuarantine(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg')], expiredIds: [1]);

        $result = $service->purgeExpired(30);

        self::assertSame(0, $result['purged']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $service->deleted);
        self::assertSame([], $service->removed);
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
    public function purgePreviewReturnsStableSortedTargets(): void
    {
        $fingerprints = $this->createMock(AssetMutationFingerprintService::class);
        $fingerprintMap = ['asset:1' => 'fingerprint-1', 'asset:2' => 'fingerprint-2'];
        $fingerprints->expects(self::exactly(2))->method('fingerprintMap')->with([1, 2])->willReturn($fingerprintMap);
        $fingerprints->method('planConfig')->willReturn(['version' => 1]);
        $service = $this->service(
            [
                1 => $this->asset('/Quarantine/a.jpg'),
                2 => $this->asset('/Quarantine/b.jpg'),
            ],
            expiredIds: [2, 1],
            mutationFingerprints: $fingerprints,
        );

        $preview = $service->previewPurge(30);

        self::assertSame([1, 2], $preview['assetIds']);
        self::assertSame(['asset:1', 'asset:2'], array_column($preview['targets'], 'id'));
        self::assertSame(2, $preview['result']['purged']);
        self::assertSame([], $service->deleted);
    }

    #[Test]
    public function staleLaterPurgeTargetPreventsEveryDeletionAndReleasesAllLocks(): void
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
        $service = $this->service(
            [
                1 => $this->asset('/Quarantine/a.jpg'),
                2 => $this->asset('/Quarantine/b.jpg'),
            ],
            loopGuard: $loopGuard,
            expiredIds: [1, 2],
            mutationFingerprints: $fingerprints,
        );

        try {
            $service->purgePlanned(30, [2, 1], [
                'asset:1' => 'fingerprint-1',
                'asset:2' => 'fingerprint-2',
            ]);
            self::fail('Expected the stale purge target to reject the plan.');
        } catch (StaleApplyPlanException) {
        }

        self::assertSame([1, 2], $acquired);
        self::assertSame([2, 1], $released);
        self::assertSame([], $service->deleted);
        self::assertSame([], $service->removed);
    }

    #[Test]
    public function listQuarantinedFiltersByTypeAndKeepsSameDayRecordsOnADateOnlyBefore(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT, mimetype TEXT)');
        $connection->executeStatement("CREATE TABLE asset_pilot_quarantine (asset_id INTEGER, original_path TEXT, quarantined_at TEXT, status TEXT NOT NULL DEFAULT 'committed')");
        $connection->insert('assets', ['id' => 1, 'path' => '/Quarantine/', 'filename' => 'a.jpg', 'type' => 'image', 'mimetype' => 'image/jpeg']);
        $connection->insert('assets', ['id' => 2, 'path' => '/Quarantine/', 'filename' => 'b.pdf', 'type' => 'document', 'mimetype' => 'application/pdf']);
        $connection->insert('asset_pilot_quarantine', ['asset_id' => 1, 'original_path' => '/Products/a.jpg', 'quarantined_at' => '2024-01-01 14:30:00']);
        $connection->insert('asset_pilot_quarantine', ['asset_id' => 2, 'original_path' => '/Docs/b.pdf', 'quarantined_at' => '2024-01-02 09:00:00']);
        $connection->insert('assets', ['id' => 3, 'path' => '/Quarantine/', 'filename' => 'pending.jpg', 'type' => 'image', 'mimetype' => 'image/jpeg']);
        $connection->insert('asset_pilot_quarantine', ['asset_id' => 3, 'original_path' => '/Products/pending.jpg', 'quarantined_at' => '2024-01-01 10:00:00', 'status' => 'pending']);

        $loopGuard = $this->createMock(LoopGuard::class);
        [$contentScanner, , $reviewedLocks, $authorization, $dependencyVerifier, $workspaceScope, $authorizedPage, $fingerprints, $assetSaver, $deletionFence] = $this->mutationSafetyDependencies($connection, loopGuard: $loopGuard);
        $service = new QuarantineService(
            $connection,
            $loopGuard,
            $reviewedLocks,
            $this->createMock(UnusedAssetFinderInterface::class),
            new EventDispatcher(),
            new NullLogger(),
            $contentScanner,
            $authorization,
            $dependencyVerifier,
            $workspaceScope,
            $authorizedPage,
            $fingerprints,
            $assetSaver,
            $deletionFence,
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
    public function listQuarantinedUsesWorkspaceFilteredTotalsAndPages(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT, mimetype TEXT)');
        $connection->executeStatement("CREATE TABLE asset_pilot_quarantine (asset_id INTEGER, original_path TEXT, quarantined_at TEXT, status TEXT NOT NULL DEFAULT 'committed')");
        $connection->executeStatement('CREATE TABLE users_workspaces_asset (userId INTEGER, cpath TEXT, view INTEGER)');
        foreach ([1, 2] as $id) {
            $connection->insert('assets', ['id' => $id, 'path' => '/Quarantine/', 'filename' => $id . '.jpg', 'type' => 'image', 'mimetype' => 'image/jpeg']);
            $connection->insert('asset_pilot_quarantine', ['asset_id' => $id, 'original_path' => '/Products/' . $id . '.jpg', 'quarantined_at' => '2026-07-14 10:00:00']);
        }
        $connection->insert('users_workspaces_asset', ['userId' => 42, 'cpath' => '/Quarantine', 'view' => 1]);
        $connection->insert('users_workspaces_asset', ['userId' => 42, 'cpath' => '/Quarantine/2.jpg', 'view' => 0]);

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
        $workspaceScope = new AssetWorkspaceQueryScope($connection, $authorization, $actors);
        $loopGuard = $this->createMock(LoopGuard::class);
        [$contentScanner, , $reviewedLocks, , $dependencyVerifier, , , $fingerprints, $assetSaver, $deletionFence] = $this->mutationSafetyDependencies(
            $connection,
            loopGuard: $loopGuard,
            authorization: $authorization,
            workspaceScope: $workspaceScope,
        );
        $stubs = [];
        foreach ([1, 2] as $id) {
            $stub = $this->createStub(Asset::class);
            $stub->method('getId')->willReturn($id);
            $stubs[$id] = $stub;
        }
        $authorizedPage = new AuthorizedAssetPage($authorization, $workspaceScope, static fn (int $id): ?Asset => $stubs[$id] ?? null);

        $service = new QuarantineService(
            $connection,
            $loopGuard,
            $reviewedLocks,
            $this->createMock(UnusedAssetFinderInterface::class),
            new EventDispatcher(),
            new NullLogger(),
            $contentScanner,
            $authorization,
            $dependencyVerifier,
            $workspaceScope,
            $authorizedPage,
            $fingerprints,
            $assetSaver,
            $deletionFence,
        );

        $result = $service->listQuarantined(limit: 1);

        self::assertNull($result['total'], 'a scoped user must not receive an SQL-count-derived quarantine total');
        self::assertNull($result['pages']);
        self::assertFalse($result['hasMore']);
        self::assertSame([1], array_column($result['items'], 'asset_id'), 'asset 2 is workspace-denied, asset 1 remains');
    }

    #[Test]
    public function theQuarantineMoveFollowsTheLoopGuardOrdering(): void
    {
        $calls = new \ArrayObject();
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
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
