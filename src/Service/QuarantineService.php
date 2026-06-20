<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
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
class QuarantineService
{
    private const int PURGE_BATCH = 1000;

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoopGuard $loopGuard,
        protected readonly UnusedAssetFinderInterface $unusedAssetFinder,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoggerInterface $logger,
        protected readonly string $quarantineFolder = '/Quarantine',
        protected readonly int $graceDays = 30,
        protected readonly ?ContentUsageScanner $contentScanner = null,
    ) {}

    /**
     * @param int[] $assetIds
     * @return array{quarantined: int, failed: int, errors: array<int|string, string>}
     */
    public function quarantine(array $assetIds): array
    {
        $quarantined = [];
        $failed = 0;
        $errors = [];

        // Authorize creating in the quarantine destination once (the move is a side effect there).
        if (!$this->targetAllowsCreate($this->quarantineFolder)) {
            foreach ($assetIds as $assetId) {
                $errors[(int) $assetId] = 'Not permitted to write to the quarantine folder';
                ++$failed;
            }

            return ['quarantined' => 0, 'failed' => $failed, 'errors' => $errors];
        }

        $folder = null;

        foreach ($assetIds as $assetId) {
            $assetId = (int) $assetId;
            try {
                $asset = $this->loadAsset($assetId);
                if ($asset === null) {
                    $errors[$assetId] = 'Asset not found';
                    ++$failed;
                    continue;
                }
                if ($asset instanceof Asset\Folder) {
                    $errors[$assetId] = 'Cannot quarantine a folder';
                    ++$failed;
                    continue;
                }
                // Re-verify it is still unused (it may have been referenced since the listing).
                if ($this->unusedAssetFinder->isReferenced($assetId)) {
                    $errors[$assetId] = 'Asset is now referenced by an object';
                    ++$failed;
                    continue;
                }
                // Quarantining moves the asset, which would break a hard-coded path reference in content.
                if ($this->isReferencedInContent($asset)) {
                    $errors[$assetId] = 'Asset is referenced in object content (text/WYSIWYG)';
                    ++$failed;
                    continue;
                }
                if (!$asset->isAllowed('publish')) {
                    $errors[$assetId] = 'Not permitted to move this asset';
                    ++$failed;
                    continue;
                }

                $folder ??= $this->resolveFolder($this->quarantineFolder);
                $originalPath = $asset->getRealFullPath();
                $this->moveGuarded($asset, $assetId, static function () use ($asset, $folder): void {
                    $asset->setParent($folder);
                }, 'Asset Pilot: quarantined to ' . $this->quarantineFolder);
                $this->recordQuarantine($assetId, $originalPath);
                $quarantined[] = $assetId;
            } catch (\Throwable $e) {
                $errors[$assetId] = $e->getMessage();
                ++$failed;
                $this->logger->error('Asset Pilot: failed to quarantine asset {id}: {error}', [
                    'id' => $assetId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if ($quarantined !== []) {
            $this->eventDispatcher->dispatch(
                new AssetMutationEvent($quarantined, 'quarantine', ['folder' => $this->quarantineFolder]),
                AssetPilotEvents::QUARANTINED,
            );
        }

        return ['quarantined' => count($quarantined), 'failed' => $failed, 'errors' => $errors];
    }

    public function restore(int $assetId): bool
    {
        $originalPath = $this->findOriginalPath($assetId);
        if ($originalPath === null) {
            return false;
        }

        $asset = $this->loadAsset($assetId);
        if ($asset === null) {
            return false;
        }

        $originalDir = \dirname($originalPath);
        if (!$asset->isAllowed('publish') || !$this->targetAllowsCreate($originalDir)) {
            throw new \RuntimeException('Not permitted to restore this asset to its original location.');
        }

        $folder = $this->resolveFolder($originalDir);
        $filename = basename($originalPath);
        $this->moveGuarded($asset, $assetId, static function () use ($asset, $folder, $filename): void {
            $asset->setParent($folder);
            $asset->setFilename($filename);
        }, 'Asset Pilot: restored from quarantine');
        $this->deleteQuarantineRecord($assetId);

        $this->eventDispatcher->dispatch(
            new AssetMutationEvent([$assetId], 'restore', ['to' => $originalPath]),
            AssetPilotEvents::RESTORED,
        );

        return true;
    }

    /**
     * @param array{type?: string, before?: string, after?: string} $filters
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function listQuarantined(int $page = 1, int $limit = 50, array $filters = []): array
    {
        $offset = (max(1, $page) - 1) * $limit;

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('q.asset_id, q.original_path, q.quarantined_at, a.path, a.filename, a.type, a.mimetype')
                ->from(Installer::TABLE_QUARANTINE, 'q')
                ->innerJoin('q', PimcoreSchema::TABLE_ASSETS, 'a', 'q.asset_id = a.id')
                ->orderBy('q.quarantined_at', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit);
            $this->applyQuarantineFilters($qb, $filters);

            // Count joins assets too, so the total matches the list (and orphan rows are excluded).
            $countQb = $this->connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Installer::TABLE_QUARANTINE, 'q')
                ->innerJoin('q', PimcoreSchema::TABLE_ASSETS, 'a', 'q.asset_id = a.id');
            $this->applyQuarantineFilters($countQb, $filters);
            $total = (int) $countQb->executeQuery()->fetchOne();

            $items = $qb->executeQuery()->fetchAllAssociative();
            foreach ($items as &$item) {
                $item['asset_id'] = (int) $item['asset_id'];
            }

            return ['items' => $items, 'total' => $total, 'page' => max(1, $page), 'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to list quarantined assets: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['items' => [], 'total' => 0, 'page' => max(1, $page), 'pages' => 0];
        }
    }

    /**
     * @param array{type?: string, before?: string, after?: string} $filters
     */
    private function applyQuarantineFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['type'])) {
            $qb->andWhere('a.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['after'])) {
            $qb->andWhere('q.quarantined_at >= :after')->setParameter('after', $filters['after']);
        }
        if (!empty($filters['before'])) {
            $qb->andWhere('q.quarantined_at <= :before')->setParameter('before', $filters['before']);
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
        $graceDays = max(0, $graceDays ?? $this->graceDays);
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $graceDays))->format('Y-m-d H:i:s');

        $purged = 0;
        $skipped = 0;
        $failed = 0;
        $deletedIds = [];

        // Bounded per run: a large backlog is chipped away across maintenance runs rather than
        // hard-deleting an unbounded set in one pass.
        foreach ($this->findExpired($cutoff, self::PURGE_BATCH) as $assetId) {
            $assetId = (int) $assetId;
            try {
                if ($this->unusedAssetFinder->isReferenced($assetId)) {
                    ++$skipped;
                    $this->logger->warning('Asset Pilot: quarantined asset {id} is now referenced; not purging.', ['id' => $assetId]);
                    continue;
                }

                $asset = $this->loadAsset($assetId);
                if ($asset === null) {
                    // The asset is already gone; drop the stale record.
                    if (!$dryRun) {
                        $this->deleteQuarantineRecord($assetId);
                    }
                    ++$purged;
                    continue;
                }

                // A reference may have been hard-coded into content while quarantined; never hard-delete then.
                if ($this->isReferencedInContent($asset)) {
                    ++$skipped;
                    $this->logger->warning('Asset Pilot: quarantined asset {id} is referenced in content; not purging.', ['id' => $assetId]);
                    continue;
                }

                if (!$asset->isAllowed('delete')) {
                    ++$skipped;
                    continue;
                }

                if ($dryRun) {
                    ++$purged;
                    continue;
                }

                $this->deleteAsset($asset);
                $this->deleteQuarantineRecord($assetId);
                $deletedIds[] = $assetId;
                ++$purged;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Asset Pilot: failed to purge quarantined asset {id}: {error}', [
                    'id' => $assetId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if ($deletedIds !== []) {
            $this->eventDispatcher->dispatch(
                new AssetMutationEvent($deletedIds, 'purge', ['grace_days' => $graceDays]),
                AssetPilotEvents::UNUSED_DELETED,
            );
        }

        return ['purged' => $purged, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Optional content-reference guard (opt-in, null when not configured): a hard-coded path
     * reference in rich-text/text fields that the dependency table does not track.
     */
    private function isReferencedInContent(Asset $asset): bool
    {
        return $this->contentScanner?->isReferencedInContent($asset) === true;
    }

    protected function moveGuarded(Asset $asset, int $assetId, callable $mutate, string $note): void
    {
        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $mutate();
            $asset->save(['versionNote' => $note]);
            $this->loopGuard->markAssetRecentlyMoved($assetId);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
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

        return $parent === null || $parent->isAllowed('create');
    }

    protected function resolveFolder(string $path): Asset\Folder
    {
        return Asset\Service::createFolderByPath($path);
    }

    protected function recordQuarantine(int $assetId, string $originalPath): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (asset_id, original_path, quarantined_at) VALUES (:asset_id, :original_path, :quarantined_at)
                 ON DUPLICATE KEY UPDATE original_path = :original_path, quarantined_at = :quarantined_at',
                Installer::TABLE_QUARANTINE,
            ),
            [
                'asset_id' => $assetId,
                'original_path' => $originalPath,
                'quarantined_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    protected function findOriginalPath(int $assetId): ?string
    {
        $path = $this->connection->createQueryBuilder()
            ->select('original_path')
            ->from(Installer::TABLE_QUARANTINE)
            ->where('asset_id = :id')
            ->setParameter('id', $assetId)
            ->executeQuery()
            ->fetchOne();

        return $path === false ? null : (string) $path;
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
            ->setParameter('cutoff', $cutoff)
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
