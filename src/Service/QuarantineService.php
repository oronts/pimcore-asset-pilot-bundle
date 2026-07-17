<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Enum\QuarantineStatus;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Soft-delete lifecycle: instead of permanently deleting an unused asset, move it to a quarantine
 * folder and record its origin, so a wrong call is reversible via restore(). The move is
 * LoopGuard-wrapped (the save must not re-enter the organize pipeline). The destructive hard-delete
 * of expired entries lives in a separate, explicitly-guarded purge.
 */
class QuarantineService implements QuarantineServiceInterface
{
    private const int PURGE_BATCH = 1000;

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoopGuard $loopGuard,
        protected readonly ReviewedAssetLockCoordinator $reviewedLocks,
        protected readonly UnusedAssetFinderInterface $unusedAssetFinder,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoggerInterface $logger,
        protected readonly ContentUsageScannerInterface $contentScanner,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly DependencyUsageVerifierInterface $dependencyVerifier,
        protected readonly AssetWorkspaceQueryScope $workspaceScope,
        protected readonly AssetMutationFingerprintService $mutationFingerprints,
        protected readonly LoopGuardedAssetSaver $assetSaver,
        protected readonly AssetDeletionFenceInterface $deletionFence,
        protected readonly string $quarantineFolder = '/Quarantine',
        protected readonly int $graceDays = 30,
        protected readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    /**
     * @param int[] $assetIds
     * @return array{quarantined: int, failed: int, errors: array<int|string, string>}
     */
    public function quarantine(array $assetIds, ?array $expectedFingerprints = null): array
    {
        $quarantined = [];
        $failed = 0;
        $errors = [];

        if (!$this->hasContentEvidence()) {
            foreach ($assetIds as $assetId) {
                $errors[(int) $assetId] = 'Content-reference verification is not configured';
            }

            return ['quarantined' => 0, 'failed' => count($assetIds), 'errors' => $errors];
        }

        // Authorize creating in the quarantine destination once (the move is a side effect there).
        if (!$this->targetAllowsCreate($this->quarantineFolder)) {
            foreach ($assetIds as $assetId) {
                $errors[(int) $assetId] = 'Not permitted to write to the quarantine folder';
                ++$failed;
            }

            return ['quarantined' => 0, 'failed' => $failed, 'errors' => $errors];
        }

        $folder = null;
        [$quarantined, $failed, $errors] = $this->withReviewedPlanLocks(
            $assetIds,
            $expectedFingerprints,
            fn (array $lockedIds): array => $this->quarantineLocked($assetIds, $lockedIds, $folder),
        );

        if ($quarantined !== []) {
            NonFatalEventDispatcher::dispatch(
                $this->eventDispatcher,
                new AssetMutationEvent($quarantined, 'quarantine', ['folder' => $this->quarantineFolder]),
                AssetPilotEvents::QUARANTINED,
                $this->logger,
            );
        }

        return ['quarantined' => count($quarantined), 'failed' => $failed, 'errors' => $errors];
    }

    /** @param int[] $assetIds @param list<int> $planLocks @return array{list<int>, int, array<int|string, string>} */
    private function quarantineLocked(array $assetIds, array $planLocks, ?Asset\Folder &$folder): array
    {
        $quarantined = [];
        $failed = 0;
        $errors = [];
        $planLockSet = array_fill_keys($planLocks, true);

        foreach ($assetIds as $assetId) {
            $assetId = (int) $assetId;
            $lockedByPlan = isset($planLockSet[$assetId]);
            if (!$lockedByPlan && !$this->loopGuard->acquireAsset($assetId)) {
                $errors[$assetId] = 'Asset is being processed by another job';
                ++$failed;
                continue;
            }

            try {
                [$asset, $error] = $this->guardQuarantineAsset($assetId);
                if ($asset === null) {
                    $errors[$assetId] = (string) $error;
                    ++$failed;
                    continue;
                }

                $folder ??= $this->resolveFolder($this->quarantineFolder);
                $this->quarantineAsset($asset, $assetId, $folder);
                $quarantined[] = $assetId;
            } catch (\Throwable $e) {
                $errors[$assetId] = 'Failed to quarantine the asset.';
                ++$failed;
                $this->logger->error('Asset Pilot: failed to quarantine asset {id}: {error}', [
                    'id' => $assetId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            } finally {
                if (!$lockedByPlan) {
                    $this->loopGuard->releaseAsset($assetId);
                }
            }
        }

        return [$quarantined, $failed, $errors];
    }

    public function recoverQuarantine(int $assetId): bool
    {
        $record = $this->findQuarantineRecord($assetId);
        $asset = $this->loadAsset($assetId);
        if ($record === null || $asset === null || !$this->isInQuarantine($asset)) {
            return false;
        }
        if ($record['status'] === QuarantineStatus::Pending) {
            $this->markQuarantineCommitted($assetId);
        }

        return true;
    }

    public function previewQuarantine(int $assetId): ?string
    {
        if (!$this->hasContentEvidence()) {
            return 'Content-reference verification is not configured';
        }
        if (!$this->targetAllowsCreate($this->quarantineFolder)) {
            return 'Not permitted to write to the quarantine folder';
        }

        return $this->guardQuarantineAsset($assetId)[1];
    }

    /** @return array{0: ?Asset, 1: ?string} */
    protected function guardQuarantineAsset(int $assetId): array
    {
        $asset = $this->loadAsset($assetId);
        if ($asset === null) {
            return [null, 'Asset not found'];
        }
        if ($asset instanceof Asset\Folder) {
            return [null, 'Cannot quarantine a folder'];
        }
        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return [null, 'Asset is locked'];
        }
        if ($this->unusedAssetFinder->isReferenced($assetId)) {
            return [null, 'Asset is now referenced by an object'];
        }
        if ($this->isReferencedInContent($asset)) {
            return [null, 'Asset is referenced in object content (text/WYSIWYG)'];
        }
        $dependencyVerdict = $this->dependencyVerifier->verdict($asset);
        if ($dependencyVerdict === DependencyUsageVerdict::Referenced) {
            return [null, 'Asset is referenced by a live Pimcore element dependency'];
        }
        if ($dependencyVerdict === DependencyUsageVerdict::Unknown) {
            return [null, 'Dependency projection is not ready or contains dirty sources'];
        }
        if (!$this->isAllowed($asset, 'publish')) {
            return [null, 'Not permitted to move this asset'];
        }

        return [$asset, null];
    }

    /**
     * @template TResult
     * @param list<int> $assetIds
     * @param array<string, string>|null $expectedFingerprints
     * @param callable(list<int>): TResult $operation
     * @return TResult
     */
    private function withReviewedPlanLocks(array $assetIds, ?array $expectedFingerprints, callable $operation): mixed
    {
        if ($expectedFingerprints === null) {
            return $operation([]);
        }

        return $this->reviewedLocks->run(
            $assetIds,
            static fn (int $assetId): \Throwable => new StaleApplyPlanException(sprintf('Asset %d is being processed. Preview the operation again.', $assetId)),
            function (array $lockedIds) use ($expectedFingerprints, $operation): mixed {
                foreach ($lockedIds as $assetId) {
                    $this->mutationFingerprints->assertUnchanged($assetId, $expectedFingerprints);
                }

                return $operation($lockedIds);
            },
        );
    }

    public function restore(int $assetId): bool
    {
        if (!$this->loopGuard->acquireAsset($assetId)) {
            return false;
        }

        try {
            return $this->restoreAssetLocked($assetId);
        } finally {
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    private function restoreAssetLocked(int $assetId): bool
    {
        $record = $this->findQuarantineRecord($assetId);
        $asset = $this->loadAsset($assetId);
        if ($record === null || $asset === null || !$this->isInQuarantine($asset)) {
            return false;
        }
        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return false;
        }
        if ($record['status'] === QuarantineStatus::Pending) {
            $this->markQuarantineCommitted($assetId);
        }

        $originalPath = $record['originalPath'];
        if (!$this->isAllowed($asset, 'publish') || !$this->targetAllowsCreate(\dirname($originalPath))) {
            throw new NotPermittedException('Not permitted to restore this asset to its original location.');
        }
        if (!$this->loopGuard->acquireTarget($originalPath)) {
            return false;
        }

        try {
            return $this->restoreTargetLocked($asset, $assetId, $originalPath);
        } finally {
            $this->loopGuard->releaseTarget($originalPath);
        }
    }

    private function restoreTargetLocked(Asset $asset, int $assetId, string $originalPath): bool
    {
        $occupant = $this->assetAtPath($originalPath);
        if ($occupant !== null && (int) $occupant->getId() !== $assetId) {
            throw new \RuntimeException(sprintf(
                'Cannot restore asset %d: another asset already occupies its original path %s.',
                $assetId,
                $originalPath,
            ));
        }

        $folder = $this->resolveFolder(\dirname($originalPath));
        $filename = basename($originalPath);
        $this->moveGuarded($asset, $assetId, static function () use ($asset, $folder, $filename): void {
            $asset->setParent($folder);
            $asset->setFilename($filename);
        }, 'Asset Pilot: restored from quarantine', $originalPath);
        $this->deleteQuarantineRecord($assetId);
        NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new AssetMutationEvent([$assetId], 'restore', ['to' => $originalPath]),
            AssetPilotEvents::RESTORED,
            $this->logger,
            ['asset_id' => $assetId],
        );

        return true;
    }

    protected function assetAtPath(string $path): ?Asset
    {
        return Asset::getByPath($path);
    }

    /**
     * @param array{type?: string, before?: string, after?: string} $filters
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function listQuarantined(int $page = 1, int $limit = 50, array $filters = []): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('q.asset_id, q.original_path, q.quarantined_at, a.path, a.filename, a.type, a.mimetype')
                ->from(Installer::TABLE_QUARANTINE, 'q')
                ->innerJoin('q', PimcoreSchema::TABLE_ASSETS, 'a', 'q.asset_id = a.id')
                ->orderBy('q.quarantined_at', 'DESC')
                ->addOrderBy('q.asset_id', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit);
            $this->applyQuarantineFilters($qb, $filters);
            $this->workspaceScope->applyView($qb, 'a', 'quarantineList');

            // Count joins assets too, so the total matches the list (and orphan rows are excluded).
            $countQb = $this->connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Installer::TABLE_QUARANTINE, 'q')
                ->innerJoin('q', PimcoreSchema::TABLE_ASSETS, 'a', 'q.asset_id = a.id');
            $this->applyQuarantineFilters($countQb, $filters);
            $this->workspaceScope->applyView($countQb, 'a', 'quarantineCount');
            $total = (int) $countQb->executeQuery()->fetchOne();

            $items = $qb->executeQuery()->fetchAllAssociative();
            foreach ($items as &$item) {
                $item['asset_id'] = (int) $item['asset_id'];
            }

            return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $limit)];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to list quarantined assets: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['items' => [], 'total' => 0, 'page' => $page, 'pages' => 0];
        }
    }

    /**
     * @param array{type?: string, before?: string, after?: string} $filters
     */
    private function applyQuarantineFilters(QueryBuilder $qb, array $filters): void
    {
        $qb->andWhere('q.status = :quarantineStatus')
            ->setParameter('quarantineStatus', QuarantineStatus::Committed->value);

        if (!empty($filters['type'])) {
            $qb->andWhere('a.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['after'])) {
            $qb->andWhere('q.quarantined_at >= :after')->setParameter('after', $filters['after']);
        }
        if (!empty($filters['before'])) {
            // A date-only bound means the whole day: extend to end-of-day so same-day records are kept.
            $before = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['before']) ? $filters['before'] . ' 23:59:59' : $filters['before'];
            $qb->andWhere('q.quarantined_at <= :before')->setParameter('before', $before);
        }
    }

    /**
     * Hard-delete quarantined assets older than the grace period, but only if they are still unused
     * (an asset that gained a reference while quarantined is skipped, never deleted). Destructive, so
     * each delete is per-asset ACL-gated; intended for the scheduled maintenance task / CLI.
     *
     * @return array{purged: int, skipped: int, failed: int}
     */
    public function purgeExpired(?int $graceDays = null, bool $dryRun = false): array
    {
        $graceDays = $this->effectiveGraceDays($graceDays);

        return $this->purgeResult(
            $graceDays,
            $this->purgeCandidates($this->expiredAssetIds($graceDays), $dryRun),
        );
    }

    /**
     * @return array{
     *     graceDays: int,
     *     assetIds: list<int>,
     *     config: array<string, mixed>,
     *     targets: list<ApplyPlanTarget>,
     *     result: array{purged: int, skipped: int, failed: int}
     * }
     */
    public function previewPurge(?int $graceDays = null): array
    {
        $graceDays = $this->effectiveGraceDays($graceDays);
        $assetIds = $this->expiredAssetIds($graceDays);
        $before = $this->mutationFingerprints->fingerprintMap($assetIds);
        $result = $this->purgeResult($graceDays, $this->purgeCandidates($assetIds, true));
        $after = $this->mutationFingerprints->fingerprintMap($assetIds);
        if ($before !== $after) {
            throw new StaleApplyPlanException('A quarantine purge candidate changed while the preview was built. Preview again.');
        }

        return [
            'graceDays' => $graceDays,
            'assetIds' => $assetIds,
            'config' => [
                'batchSize' => self::PURGE_BATCH,
                'mutation' => $this->mutationFingerprints->planConfig(),
                'quarantineFolder' => $this->quarantineFolder,
            ],
            'targets' => array_map(
                static fn (string $id, string $fingerprint): ApplyPlanTarget => new ApplyPlanTarget($id, $fingerprint),
                array_keys($after),
                array_values($after),
            ),
            'result' => $result,
        ];
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, string> $expectedFingerprints
     * @return array{purged: int, skipped: int, failed: int}
     */
    public function purgePlanned(?int $graceDays, array $assetIds, array $expectedFingerprints): array
    {
        $graceDays = $this->effectiveGraceDays($graceDays);
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        sort($assetIds, SORT_NUMERIC);
        return $this->withReviewedPlanLocks(
            $assetIds,
            $expectedFingerprints,
            function (array $lockedIds) use ($assetIds, $graceDays): array {
                if ($this->expiredAssetIds($graceDays) !== $assetIds) {
                    throw new StaleApplyPlanException('The quarantine purge selection changed after preview. Preview again.');
                }

                return $this->purgeResult($graceDays, $this->purgeCandidates($lockedIds, false, true));
            },
        );
    }

    private function effectiveGraceDays(?int $graceDays): int
    {
        return max(0, $graceDays ?? $this->graceDays);
    }

    /** @return list<int> */
    private function expiredAssetIds(int $graceDays): array
    {
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $graceDays))->format('Y-m-d H:i:s');
        $assetIds = array_values(array_unique($this->findExpired($cutoff, self::PURGE_BATCH)));
        sort($assetIds, SORT_NUMERIC);

        return $assetIds;
    }

    /**
     * @param array{int, int, int, list<int>} $counts
     * @return array{purged: int, skipped: int, failed: int}
     */
    private function purgeResult(int $graceDays, array $counts): array
    {
        [$purged, $skipped, $failed, $deletedIds] = $counts;

        if ($deletedIds !== []) {
            NonFatalEventDispatcher::dispatch(
                $this->eventDispatcher,
                new AssetMutationEvent($deletedIds, 'purge', ['grace_days' => $graceDays]),
                AssetPilotEvents::UNUSED_DELETED,
                $this->logger,
            );
        }

        return ['purged' => $purged, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** @param list<int> $assetIds @return array{int, int, int, list<int>} */
    private function purgeCandidates(array $assetIds, bool $dryRun, bool $alreadyLocked = false): array
    {
        $purged = 0;
        $skipped = 0;
        $failed = 0;
        $deletedIds = [];

        foreach ($assetIds as $assetId) {
            $assetId = (int) $assetId;
            if (!$alreadyLocked && !$this->loopGuard->acquireAsset($assetId)) {
                ++$skipped;
                continue;
            }

            try {
                $result = $this->purgeCandidate($assetId, $dryRun);
                if (!$result['purged']) {
                    ++$skipped;
                    continue;
                }
                ++$purged;
                if ($result['deleted']) {
                    $deletedIds[] = $assetId;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Asset Pilot: failed to purge quarantined asset {id}: {error}', [
                    'id' => $assetId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            } finally {
                if (!$alreadyLocked) {
                    $this->loopGuard->releaseAsset($assetId);
                }
            }
        }

        return [$purged, $skipped, $failed, $deletedIds];
    }

    /** @return array{purged: bool, deleted: bool} */
    private function purgeCandidate(int $assetId, bool $dryRun): array
    {
        $record = $this->findQuarantineRecord($assetId);
        if ($record === null || $record['status'] !== QuarantineStatus::Committed) {
            return ['purged' => false, 'deleted' => false];
        }

        return $dryRun ? $this->dryRunPurge($assetId) : $this->livePurge($assetId);
    }

    /**
     * Non-mutating dry-run assessment; fence-free because it commits no delete.
     *
     * @return array{purged: bool, deleted: bool}
     */
    protected function dryRunPurge(int $assetId): array
    {
        if ($this->unusedAssetFinder->isReferenced($assetId)) {
            return ['purged' => $this->rejectPurge($assetId, 'is now referenced'), 'deleted' => false];
        }
        $asset = $this->loadAsset($assetId);
        if ($asset === null) {
            return ['purged' => true, 'deleted' => false];
        }
        if (!$this->canPurge($asset, $assetId)) {
            return ['purged' => false, 'deleted' => false];
        }

        return ['purged' => true, 'deleted' => false];
    }

    /**
     * Fenced live purge: the reference/protection re-reads and the hard delete run inside a deletion fence,
     * refreshed immediately before the delete and always token-released.
     *
     * @return array{purged: bool, deleted: bool}
     */
    protected function livePurge(int $assetId): array
    {
        $fenceToken = $this->deletionFence->acquire($assetId, 'quarantine_purge');
        if ($fenceToken === null) {
            return ['purged' => $this->rejectPurge($assetId, 'is already being deleted by another operation'), 'deleted' => false];
        }

        try {
            if ($this->unusedAssetFinder->isReferenced($assetId)) {
                return ['purged' => $this->rejectPurge($assetId, 'is now referenced'), 'deleted' => false];
            }
            $asset = $this->loadAsset($assetId);
            if ($asset === null) {
                $this->deleteQuarantineRecord($assetId);

                return ['purged' => true, 'deleted' => false];
            }
            if (!$this->canPurge($asset, $assetId)) {
                return ['purged' => false, 'deleted' => false];
            }

            $this->loopGuard->refreshAsset($assetId);
            $this->deletionFence->refreshOrFail($assetId, $fenceToken);
            $this->deleteAsset($asset);
            $this->deleteQuarantineRecord($assetId);

            return ['purged' => true, 'deleted' => true];
        } finally {
            $this->deletionFence->release($assetId, $fenceToken);
        }
    }

    private function canPurge(Asset $asset, int $assetId): bool
    {
        if (!$this->isInQuarantine($asset)) {
            return $this->rejectPurge($assetId, 'record does not match its current path');
        }
        if (!$this->hasContentEvidence()) {
            return $this->rejectPurge($assetId, 'content-reference verification is not configured');
        }
        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return $this->rejectPurge($assetId, 'is locked');
        }
        if ($this->isReferencedInContent($asset)) {
            return $this->rejectPurge($assetId, 'is referenced in content');
        }
        $dependencyVerdict = $this->dependencyVerifier->verdict($asset);
        if ($dependencyVerdict === DependencyUsageVerdict::Referenced) {
            return $this->rejectPurge($assetId, 'has a live Pimcore element dependency');
        }
        if ($dependencyVerdict === DependencyUsageVerdict::Unknown) {
            return $this->rejectPurge($assetId, 'has an unverified dependency projection');
        }

        return $this->isAllowed($asset, 'delete');
    }

    private function rejectPurge(int $assetId, string $reason): bool
    {
        $this->logger->warning('Asset Pilot: quarantined asset {id} {reason}; not purging.', [
            'id' => $assetId,
            'reason' => $reason,
        ]);

        return false;
    }

    /**
     * Optional content-reference guard (opt-in, null when not configured): a hard-coded path
     * reference in rich-text/text fields that the dependency table does not track.
     */
    private function isReferencedInContent(Asset $asset): bool
    {
        return $this->contentScanner->freshlyReferencedInContent($asset);
    }

    private function hasContentEvidence(): bool
    {
        return $this->contentScanner->canVerify();
    }

    protected function moveGuarded(Asset $asset, int $assetId, callable $mutate, string $note, ?string $targetPath = null): void
    {
        unset($assetId);
        $this->assetSaver->save(
            $asset,
            static function (Asset $mutable) use ($mutate): void {
                unset($mutable);
                $mutate();
            },
            ['versionNote' => $note],
            $targetPath === null ? null : function () use ($targetPath): void {
                $this->loopGuard->refreshTarget($targetPath);
            },
        );
    }

    protected function quarantineAsset(Asset $asset, int $assetId, Asset\Folder $folder): void
    {
        $currentPath = $asset->getRealFullPath();
        $record = $this->findQuarantineRecord($assetId);
        if ($this->isInQuarantine($asset)) {
            if ($record === null) {
                throw new \RuntimeException('The asset is already quarantined but its origin record is missing.');
            }
            if ($record['status'] === QuarantineStatus::Pending) {
                $this->markQuarantineCommitted($assetId);
            }

            return;
        }

        $targetPath = rtrim($this->quarantineFolder, '/') . '/' . basename($currentPath);
        if (!$this->loopGuard->acquireTarget($targetPath)) {
            throw new \RuntimeException('The quarantine target path is being allocated by another job.');
        }

        try {
            $occupant = $this->assetAtPath($targetPath);
            if ($occupant !== null && (int) $occupant->getId() !== $assetId) {
                throw new \RuntimeException(sprintf('Cannot quarantine asset %d: target path %s is occupied.', $assetId, $targetPath));
            }

            if ($record === null) {
                $this->createPendingQuarantineRecord($assetId, $currentPath);
            } elseif ($record['status'] === QuarantineStatus::Committed) {
                $this->markQuarantinePending($assetId);
            }

            $this->moveGuarded($asset, $assetId, static function () use ($asset, $folder): void {
                $asset->setParent($folder);
            }, 'Asset Pilot: quarantined to ' . $this->quarantineFolder, $targetPath);
            $this->markQuarantineCommitted($assetId);
        } finally {
            $this->loopGuard->releaseTarget($targetPath);
        }
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    /**
     * Whether the current user may create under $path (checked on the nearest existing ancestor,
     * since the move/restore may have to recreate folders). True on CLI where there is no user.
     */
    protected function targetAllowsCreate(string $path): bool
    {
        $parent = AssetFolders::nearestExisting($path);

        return $parent === null || $this->isAllowed($parent, 'create');
    }

    private function isAllowed(Asset $asset, string $permission): bool
    {
        return $this->authorization->isAllowed($asset, $permission);
    }

    protected function resolveFolder(string $path): Asset\Folder
    {
        return Asset\Service::createFolderByPath($path);
    }

    protected function createPendingQuarantineRecord(int $assetId, string $originalPath): void
    {
        $this->connection->insert(Installer::TABLE_QUARANTINE, [
            'asset_id' => $assetId,
            'original_path' => $originalPath,
            'quarantined_at' => $this->now(),
            'status' => QuarantineStatus::Pending->value,
        ]);
    }

    protected function markQuarantinePending(int $assetId): void
    {
        $updated = $this->connection->update(Installer::TABLE_QUARANTINE, [
            'status' => QuarantineStatus::Pending->value,
            'quarantined_at' => $this->now(),
        ], ['asset_id' => $assetId]);
        if ($updated !== 1) {
            throw new \RuntimeException(sprintf('Cannot mark quarantine record for asset %d as pending.', $assetId));
        }
    }

    protected function markQuarantineCommitted(int $assetId): void
    {
        $updated = $this->connection->update(Installer::TABLE_QUARANTINE, [
            'status' => QuarantineStatus::Committed->value,
            'quarantined_at' => $this->now(),
        ], ['asset_id' => $assetId]);
        if ($updated !== 1) {
            throw new \RuntimeException(sprintf('Cannot commit quarantine record for asset %d.', $assetId));
        }
    }

    /** @return array{originalPath: string, status: QuarantineStatus}|null */
    protected function findQuarantineRecord(int $assetId): ?array
    {
        $record = $this->connection->createQueryBuilder()
            ->select('original_path', 'status')
            ->from(Installer::TABLE_QUARANTINE)
            ->where('asset_id = :id')
            ->setParameter('id', $assetId)
            ->executeQuery()
            ->fetchAssociative();
        if ($record === false) {
            return null;
        }

        $status = QuarantineStatus::tryFrom((string) $record['status']);
        if ($status === null) {
            throw new \RuntimeException(sprintf('Quarantine record for asset %d has an invalid status.', $assetId));
        }

        return ['originalPath' => (string) $record['original_path'], 'status' => $status];
    }

    private function isInQuarantine(Asset $asset): bool
    {
        $root = rtrim('/' . ltrim($this->quarantineFolder, '/'), '/');

        return $root !== ''
            && $root !== '/'
            && str_starts_with('/' . ltrim($asset->getRealFullPath(), '/'), $root . '/');
    }

    protected function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    protected function deleteQuarantineRecord(int $assetId): void
    {
        $this->connection->delete(Installer::TABLE_QUARANTINE, ['asset_id' => $assetId]);
    }

    /**
     * @return list<int> oldest-first asset ids quarantined before the cutoff, capped at $limit
     */
    protected function findExpired(string $cutoff, int $limit): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('asset_id')
            ->from(Installer::TABLE_QUARANTINE)
            ->where('quarantined_at < :cutoff')
            ->andWhere('status = :status')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('status', QuarantineStatus::Committed->value)
            ->orderBy('quarantined_at', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    protected function deleteAsset(Asset $asset): void
    {
        $asset->delete();
    }
}
