<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
use Oronts\AssetPilotBundle\Service\Query\AssetSortColumns;
use Oronts\AssetPilotBundle\Service\Query\AssetStorageSize;
use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use Oronts\AssetPilotBundle\Service\Query\ConfidenceFilter;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class UnusedAssetFinder implements UnusedAssetFinderInterface
{
    private const string UNUSED_STATS_CACHE_KEY = 'asset_pilot.unused_stats';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ConfidenceScorerInterface $scorer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
        private readonly ?ContentUsageScanner $contentScanner = null,
        private readonly ?StatsCache $statsCache = null,
        private readonly int $statsTtl = 0,
    ) {}

    /**
     * Find assets not referenced by any object/document via the dependencies table.
     *
     * @param array{
     *     type?: string|string[],
     *     extension?: string|string[],
     *     before?: string,
     *     after?: string,
     *     folder?: string,
     *     confidence?: string,
     * } $filters
     */
    public function findUnused(array $filters = [], int $page = 1, int $limit = 50, ?string $sort = null, ?string $order = null): array
    {
        $this->rejectUnsupportedSizeFilters($filters);

        $offset = ($page - 1) * $limit;
        [$sortColumn, $sortDir] = SortWhitelist::resolve($sort, $order, AssetSortColumns::MAP, AssetSortColumns::DEFAULT);

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('a.id, a.path, a.filename, a.type, a.mimetype, a.creationDate as created_at, a.modificationDate as modified_at')
                ->addSelect('CASE WHEN lp.data = \'1\' THEN 1 ELSE 0 END as locked')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a')
                ->leftJoin('a', PimcoreSchema::TABLE_PROPERTIES, 'lp', 'lp.cid = a.id AND lp.ctype = :lock_ctype AND lp.name = :lock_prop')
                ->setParameter('lock_ctype', PimcoreSchema::ELEMENT_TYPE_ASSET)
                ->setParameter('lock_prop', $this->lockProperty)
                ->orderBy($sortColumn, $sortDir)
                ->setFirstResult($offset)
                ->setMaxResults($limit);
            $this->applyUnusedPredicate($qb);

            $countQb = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as total')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a');
            $this->applyUnusedPredicate($countQb);

            $this->applyFilters($qb, $filters);
            $this->applyFilters($countQb, $filters);

            $total = (int) $countQb->executeQuery()->fetchOne();
            $items = $qb->executeQuery()->fetchAllAssociative();

            // Convert timestamps to dates
            foreach ($items as &$item) {
                $item['created_at'] = $item['created_at'] ? date('Y-m-d H:i:s', (int) $item['created_at']) : null;
                $item['modified_at'] = $item['modified_at'] ? date('Y-m-d H:i:s', (int) $item['modified_at']) : null;
                $item['full_path'] = rtrim($item['path'] ?? '', '/') . '/' . ($item['filename'] ?? '');
                $item['locked'] = (bool) ($item['locked'] ?? false);
                $item['file_size'] = $this->fileSize($item['full_path']);
            }

            $items = $this->scorer->score($items);

            return [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to find unused assets: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['items' => [], 'total' => 0, 'page' => $page, 'pages' => 0];
        }
    }

    public function countUnused(array $filters = []): int
    {
        $this->rejectUnsupportedSizeFilters($filters);

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as total')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a');
            $this->applyUnusedPredicate($qb);

            $this->applyFilters($qb, $filters);

            return (int) $qb->executeQuery()->fetchOne();
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to count unused assets: {error}', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Web-path stats: served from the short-TTL cache (the unbounded scan + per-asset storage stat is
     * too costly to run on every request). getUnusedStats() stays uncached for the schedule capture,
     * which needs live numbers. The cache is busted on the bundle's own delete/move.
     */
    public function getUnusedStatsCached(): array
    {
        return $this->statsCache?->remember(self::UNUSED_STATS_CACHE_KEY, $this->statsTtl, fn (): array => $this->getUnusedStats())
            ?? $this->getUnusedStats();
    }

    public function getUnusedStats(): array
    {
        try {
            // The assets table has no size column, so real byte totals require reading each unused
            // asset's size from storage by path. This is an on-demand panel, not a hot path.
            $qb = $this->connection->createQueryBuilder()
                ->select('a.id', 'a.type', 'a.path', 'a.filename')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a');
            $this->applyUnusedPredicate($qb);

            return $this->aggregateStats($qb->executeQuery()->fetchAllAssociative());
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get unused asset stats: {error}', [
                'error' => $e->getMessage(),
            ]);

            return ['totalCount' => 0, 'totalSize' => 0, 'totalSizeFormatted' => ByteFormat::human(0), 'byType' => []];
        }
    }

    /**
     * @param list<array{id: mixed, type: mixed, path: mixed, filename: mixed}> $rows
     * @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, byType: list<array{type: string, count: int, total_size: int}>}
     */
    protected function aggregateStats(array $rows): array
    {
        $totalSize = 0;
        $byType = [];
        foreach ($rows as $row) {
            $type = (string) $row['type'];
            $size = $this->fileSize(rtrim((string) ($row['path'] ?? ''), '/') . '/' . ((string) ($row['filename'] ?? '')));
            $byType[$type] ??= ['count' => 0, 'total_size' => 0];
            $byType[$type]['count']++;
            $byType[$type]['total_size'] += $size;
            $totalSize += $size;
        }

        uasort($byType, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $byTypeList = [];
        foreach ($byType as $type => $agg) {
            $byTypeList[] = ['type' => $type, 'count' => $agg['count'], 'total_size' => $agg['total_size']];
        }

        return [
            'totalCount' => count($rows),
            'totalSize' => $totalSize,
            'totalSizeFormatted' => ByteFormat::human($totalSize),
            'byType' => $byTypeList,
        ];
    }

    protected function fileSize(string $fullPath): int
    {
        return AssetStorageSize::bytes($fullPath);
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    protected function createTargetFolder(string $path): Asset\Folder
    {
        return Asset\Service::createFolderByPath($path);
    }

    protected function nearestExistingFolder(string $path): ?Asset\Folder
    {
        return AssetFolders::nearestExisting($path);
    }

    /**
     * @param int[] $assetIds
     * @return array{deleted: int, failed: int, errors: array<int, string>}
     */
    public function deleteAssets(array $assetIds): array
    {
        $deletedIds = [];
        $failed = 0;
        $errors = [];

        foreach ($assetIds as $id) {
            try {
                $asset = $this->loadAsset($id);
                if ($asset === null) {
                    $errors[$id] = 'Asset not found';
                    $failed++;
                    continue;
                }

                if ($asset instanceof Asset\Folder) {
                    $errors[$id] = 'Cannot delete folders';
                    $failed++;
                    continue;
                }

                // Per-asset Pimcore workspace ACL (defence in depth over the flat operate permission).
                // isAllowed() resolves the current user itself and returns true on CLI.
                if (!$asset->isAllowed('delete')) {
                    $errors[$id] = 'Not permitted to delete this asset';
                    $failed++;
                    continue;
                }

                // Verify it's actually unused (race condition safety)
                if ($this->isReferenced($id)) {
                    $errors[$id] = 'Asset is now referenced by an object';
                    $failed++;
                    continue;
                }

                if ($this->isReferencedInContent($asset)) {
                    $errors[$id] = 'Asset is referenced in object content (text/WYSIWYG)';
                    $failed++;
                    continue;
                }

                $asset->delete();
                $deletedIds[] = $id;

                $this->logger->info('Asset Pilot: deleted unused asset {id} at {path}', [
                    'id' => $id,
                    'path' => $asset->getRealFullPath(),
                ]);
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                $failed++;
            }
        }

        if ($deletedIds !== []) {
            $this->statsCache?->delete(self::UNUSED_STATS_CACHE_KEY);
            $this->eventDispatcher->dispatch(new AssetMutationEvent($deletedIds, 'unused_delete'), AssetPilotEvents::UNUSED_DELETED);
        }

        return ['deleted' => count($deletedIds), 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @param int[] $assetIds
     * @return array{moved: int, failed: int, errors: array<int, string>}
     */
    public function moveAssets(array $assetIds, string $targetFolder): array
    {
        $movedIds = [];
        $failed = 0;
        $errors = [];

        // Authorize before creating anything: createFolderByPath would create the whole target tree as
        // a side effect, so check the create ACL on the nearest existing ancestor first (Pimcore's
        // workspace ACL for placing an element; isAllowed() returns true on CLI).
        $parent = $this->nearestExistingFolder($targetFolder);
        if ($parent !== null && !$parent->isAllowed('create')) {
            return ['moved' => 0, 'failed' => count($assetIds), 'errors' => [-1 => 'Not permitted to move assets into the target folder']];
        }

        try {
            $folder = $this->createTargetFolder($targetFolder);
        } catch (\Throwable $e) {
            return ['moved' => 0, 'failed' => count($assetIds), 'errors' => [-1 => 'Failed to create folder: ' . $e->getMessage()]];
        }

        foreach ($assetIds as $id) {
            try {
                $asset = $this->loadAsset($id);
                if ($asset === null) {
                    $errors[$id] = 'Asset not found';
                    $failed++;
                    continue;
                }

                if ($asset instanceof Asset\Folder) {
                    $errors[$id] = 'Cannot move folders';
                    $failed++;
                    continue;
                }

                if (!$asset->isAllowed('publish')) {
                    $errors[$id] = 'Not permitted to move this asset';
                    $failed++;
                    continue;
                }

                // Re-verify it is still unused (it may have been referenced since the listing),
                // mirroring deleteAssets — both are destructive paths and must recheck state.
                if ($this->isReferenced($id)) {
                    $errors[$id] = 'Asset is now referenced by an object';
                    $failed++;
                    continue;
                }

                // Moving changes the path, which would break a hard-coded path reference in content.
                if ($this->isReferencedInContent($asset)) {
                    $errors[$id] = 'Asset is referenced in object content (text/WYSIWYG)';
                    $failed++;
                    continue;
                }

                $asset->setParent($folder);
                $asset->save();
                $movedIds[] = $id;

                $this->logger->info('Asset Pilot: moved unused asset {id} to {path}', [
                    'id' => $id,
                    'path' => $targetFolder,
                ]);
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                $failed++;
            }
        }

        if ($movedIds !== []) {
            $this->statsCache?->delete(self::UNUSED_STATS_CACHE_KEY);
            $this->eventDispatcher->dispatch(new AssetMutationEvent($movedIds, 'unused_move', ['targetFolder' => $targetFolder]), AssetPilotEvents::UNUSED_MOVED);
        }

        return ['moved' => count($movedIds), 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Restrict a query on the `assets` table (alias `a`) to non-folder assets that no element
     * references. The shared predicate behind findUnused/countUnused/getUnusedStats.
     */
    protected function applyUnusedPredicate(QueryBuilder $qb): void
    {
        // NOT EXISTS (not NOT IN): null-safe and short-circuits. Distinct param name — filters['folder']
        // also binds :folder and would otherwise clobber this exclusion.
        $qb
            ->andWhere('a.type != :notFolderType')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s d WHERE d.targetid = a.id AND d.targettype = :assetType)',
                PimcoreSchema::TABLE_DEPENDENCIES,
            ))
            ->setParameter('notFolderType', PimcoreSchema::ASSET_TYPE_FOLDER)
            ->setParameter('assetType', PimcoreSchema::ELEMENT_TYPE_ASSET);
    }

    /**
     * Optional content-reference guard (opt-in, null when not configured): catches a hard-coded path
     * reference in rich-text/text fields that the dependency table does not track.
     */
    private function isReferencedInContent(Asset $asset): bool
    {
        return $this->contentScanner?->isReferencedInContent($asset) === true;
    }

    public function isReferenced(int $assetId): bool
    {
        $count = (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(PimcoreSchema::TABLE_DEPENDENCIES)
            ->where('targetid = :id')
            ->andWhere('targettype = :type')
            ->setParameter('id', $assetId)
            ->setParameter('type', PimcoreSchema::ELEMENT_TYPE_ASSET)
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    // The assets table has no size column; post-filtering would corrupt the count on a delete path,
    // so reject size filters loudly rather than silently ignore them (data-loss risk).
    private function rejectUnsupportedSizeFilters(array $filters): void
    {
        if (isset($filters['minSize']) || isset($filters['maxSize'])) {
            throw new \InvalidArgumentException(
                'Asset Pilot: size filtering (minSize/maxSize) is not supported because the Pimcore '
                . 'assets table has no size column. Filter by type, extension, folder, or date instead.',
            );
        }
    }

    protected function applyFilters($qb, array $filters): void
    {
        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : explode(',', $filters['type']);
            $qb->andWhere($qb->expr()->in('a.type', ':types'))
                ->setParameter('types', $types, ArrayParameterType::STRING);
        }

        if (!empty($filters['extension'])) {
            $extensions = is_array($filters['extension']) ? $filters['extension'] : explode(',', $filters['extension']);
            $conditions = [];
            foreach ($extensions as $i => $ext) {
                $param = 'ext_' . $i;
                $conditions[] = 'a.filename LIKE :' . $param;
                $qb->setParameter($param, '%.' . Like::escape(ltrim(trim($ext), '.')));
            }
            $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
        }

        if (!empty($filters['before'])) {
            $timestamp = strtotime($filters['before']);
            if ($timestamp !== false) {
                $qb->andWhere('a.modificationDate < :before')
                    ->setParameter('before', $timestamp);
            }
        }

        if (!empty($filters['after'])) {
            $timestamp = strtotime($filters['after']);
            if ($timestamp !== false) {
                $qb->andWhere('a.modificationDate > :after')
                    ->setParameter('after', $timestamp);
            }
        }

        if (!empty($filters['folder'])) {
            $qb->andWhere('a.path LIKE :folder')
                ->setParameter('folder', Like::escape(rtrim($filters['folder'], '/')) . '/%');
        }

        $confidence = ConfidenceLevel::tryFrom((string) ($filters['confidence'] ?? ''));
        if ($confidence !== null) {
            $this->applyConfidenceFilter($qb, $confidence);
        }
    }

    private function applyConfidenceFilter(QueryBuilder $qb, ConfidenceLevel $level): void
    {
        $spec = ConfidenceFilter::build(
            $level,
            $this->lockProperty,
            AuditLogger::TABLE_NAME,
            time(),
            $this->scorer->getRecentlyUploadedDays(),
            $this->scorer->getProbablyUnusedDays(),
        );

        foreach ($spec['conditions'] as $condition) {
            $qb->andWhere($condition);
        }
        foreach ($spec['params'] as $key => $value) {
            $qb->setParameter($key, $value);
        }
    }

}
