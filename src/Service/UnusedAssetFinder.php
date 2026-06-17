<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Service\Query\AssetSortColumns;
use Oronts\AssetPilotBundle\Service\Query\ConfidenceFilter;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class UnusedAssetFinder implements UnusedAssetFinderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ConfidenceScorer $scorer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $lockProperty = 'asset_pilot_locked',
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

                try {
                    $asset = \Pimcore\Model\Asset::getById((int) $item['id']);
                    $item['file_size'] = $asset !== null ? (int) $asset->getFileSize() : 0;
                } catch (\Throwable) {
                    $item['file_size'] = 0;
                }
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

    public function getUnusedStats(): array
    {
        try {
            // The assets table has no size column, so real byte totals require loading each unused
            // asset and reading its storage size. This is an on-demand panel, not a hot path.
            $qb = $this->connection->createQueryBuilder()
                ->select('a.id', 'a.type')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a');
            $this->applyUnusedPredicate($qb);

            return $this->aggregateStats($qb->executeQuery()->fetchAllAssociative());
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get unused asset stats: {error}', [
                'error' => $e->getMessage(),
            ]);

            return ['totalCount' => 0, 'totalSize' => 0, 'totalSizeFormatted' => $this->formatBytes(0), 'byType' => []];
        }
    }

    /**
     * @param list<array{id: mixed, type: mixed}> $rows
     * @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, byType: list<array{type: string, count: int, total_size: int}>}
     */
    protected function aggregateStats(array $rows): array
    {
        $totalSize = 0;
        $byType = [];
        foreach ($rows as $row) {
            $type = (string) $row['type'];
            $size = $this->fileSize((int) $row['id']);
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
            'totalSizeFormatted' => $this->formatBytes($totalSize),
            'byType' => $byTypeList,
        ];
    }

    protected function fileSize(int $assetId): int
    {
        $asset = Asset::getById($assetId);

        return $asset !== null ? (int) $asset->getFileSize() : 0;
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
                $asset = Asset::getById($id);
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

                // Verify it's actually unused (race condition safety)
                if ($this->isReferenced($id)) {
                    $errors[$id] = 'Asset is now referenced by an object';
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

        try {
            $folder = Asset\Service::createFolderByPath($targetFolder);
        } catch (\Throwable $e) {
            return ['moved' => 0, 'failed' => count($assetIds), 'errors' => [-1 => 'Failed to create folder: ' . $e->getMessage()]];
        }

        foreach ($assetIds as $id) {
            try {
                $asset = Asset::getById($id);
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
            $this->eventDispatcher->dispatch(new AssetMutationEvent($movedIds, 'unused_move', ['targetFolder' => $targetFolder]), AssetPilotEvents::UNUSED_MOVED);
        }

        return ['moved' => count($movedIds), 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Restrict a query on the `assets` table (alias `a`) to non-folder assets that no element
     * references. The shared predicate behind findUnused/countUnused/getUnusedStats.
     */
    private function applyUnusedPredicate(QueryBuilder $qb): void
    {
        // Distinct param name: the user-supplied `filters['folder']` path filter also binds :folder,
        // which previously clobbered this type exclusion when both were present.
        $qb
            ->andWhere('a.type != :notFolderType')
            ->andWhere(sprintf(
                'a.id NOT IN (SELECT d.targetid FROM %s d WHERE d.targettype = :assetType)',
                PimcoreSchema::TABLE_DEPENDENCIES,
            ))
            ->setParameter('notFolderType', PimcoreSchema::ASSET_TYPE_FOLDER)
            ->setParameter('assetType', PimcoreSchema::ELEMENT_TYPE_ASSET);
    }

    private function isReferenced(int $assetId): bool
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

    private function applyFilters($qb, array $filters): void
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
            ConfidenceScorer::RECENTLY_UPLOADED_DAYS,
            ConfidenceScorer::PROBABLY_UNUSED_DAYS,
        );

        foreach ($spec['conditions'] as $condition) {
            $qb->andWhere($condition);
        }
        foreach ($spec['params'] as $key => $value) {
            $qb->setParameter($key, $value);
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
