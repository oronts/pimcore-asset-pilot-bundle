<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Service\Query\AssetSortColumns;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

class AssetSearchService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $lockProperty = 'asset_pilot_locked',
    ) {}

    /**
     * @param array{q?: string, type?: string, folder?: string, objectId?: int} $filters
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public function search(array $filters = [], int $page = 1, int $limit = 50, ?string $sort = null, ?string $order = null): array
    {
        $offset = ($page - 1) * $limit;
        [$sortColumn, $sortDir] = SortWhitelist::resolve($sort, $order, AssetSortColumns::MAP, AssetSortColumns::DEFAULT);

        try {
            $qb = $this->createBaseQuery()
                ->orderBy($sortColumn, $sortDir)
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $countQb = $this->createCountQuery();

            $this->applySearchFilters($qb, $filters);
            $this->applySearchFilters($countQb, $filters);

            $total = (int) $countQb->executeQuery()->fetchOne();
            $items = $this->hydrateItems($qb->executeQuery()->fetchAllAssociative());

            return $this->paginatedResponse($items, $total, $page, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: asset search failed: {error}', ['error' => $e->getMessage()]);

            return $this->paginatedResponse([], 0, $page, $limit);
        }
    }

    /**
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public function findByObject(int $objectId, int $page = 1, int $limit = 50, ?string $type = null, ?string $sort = null, ?string $order = null): array
    {
        $offset = ($page - 1) * $limit;
        [$sortColumn, $sortDir] = SortWhitelist::resolve($sort, $order, AssetSortColumns::MAP, AssetSortColumns::DEFAULT);

        try {
            $depFilter = 'a.id IN (SELECT d.targetid FROM dependencies d WHERE d.sourceid = :objId AND d.sourcetype = :srcType AND d.targettype = :tgtType)';

            $qb = $this->createBaseQuery()
                ->andWhere($depFilter)
                ->setParameter('objId', $objectId)
                ->setParameter('srcType', 'object')
                ->setParameter('tgtType', 'asset')
                ->orderBy($sortColumn, $sortDir)
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $countQb = $this->createCountQuery()
                ->andWhere($depFilter)
                ->setParameter('objId', $objectId)
                ->setParameter('srcType', 'object')
                ->setParameter('tgtType', 'asset');

            if (!empty($type)) {
                $qb->andWhere('a.type = :type')->setParameter('type', $type);
                $countQb->andWhere('a.type = :type')->setParameter('type', $type);
            }

            $total = (int) $countQb->executeQuery()->fetchOne();
            $items = $this->hydrateItems($qb->executeQuery()->fetchAllAssociative());

            return $this->paginatedResponse($items, $total, $page, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: assets-by-object failed: {error}', ['error' => $e->getMessage()]);

            return $this->paginatedResponse([], 0, $page, $limit);
        }
    }

    private function createBaseQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('a.id, a.path, a.filename, a.type, a.mimetype, a.creationDate as created_at, a.modificationDate as modified_at')
            ->addSelect('CASE WHEN lp.data = \'1\' THEN 1 ELSE 0 END as locked')
            ->from('assets', 'a')
            ->leftJoin('a', 'properties', 'lp', 'lp.cid = a.id AND lp.ctype = :lock_ctype AND lp.name = :lock_prop')
            ->where('a.type != :folder_type')
            ->setParameter('folder_type', 'folder')
            ->setParameter('lock_ctype', 'asset')
            ->setParameter('lock_prop', $this->lockProperty);
    }

    private function createCountQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('COUNT(*) as total')
            ->from('assets', 'a')
            ->where('a.type != :folder_type')
            ->setParameter('folder_type', 'folder');
    }

    private function applySearchFilters(QueryBuilder $qb, array $filters): void
    {
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $qb->andWhere('(a.filename LIKE :q OR a.path LIKE :q)')
                ->setParameter('q', '%' . $q . '%');
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('a.type = :type')->setParameter('type', $filters['type']);
        }

        if (!empty($filters['folder'])) {
            $folderPath = rtrim($filters['folder'], '/') . '/%';
            $qb->andWhere('a.path LIKE :folder_path')->setParameter('folder_path', $folderPath);
        }

        $objectId = (int) ($filters['objectId'] ?? 0);
        if ($objectId > 0) {
            $qb->andWhere('a.id IN (SELECT d.targetid FROM dependencies d WHERE d.sourceid = :objId AND d.sourcetype = :srcType AND d.targettype = :tgtType)')
                ->setParameter('objId', $objectId)
                ->setParameter('srcType', 'object')
                ->setParameter('tgtType', 'asset');
        }
    }

    private function hydrateItems(array $items): array
    {
        foreach ($items as &$item) {
            $item['created_at'] = $item['created_at'] ? date('Y-m-d H:i:s', (int) $item['created_at']) : null;
            $item['modified_at'] = $item['modified_at'] ? date('Y-m-d H:i:s', (int) $item['modified_at']) : null;
            $item['full_path'] = rtrim($item['path'] ?? '', '/') . '/' . ($item['filename'] ?? '');
            $item['locked'] = (bool) ($item['locked'] ?? false);

            try {
                $asset = Asset::getById((int) $item['id']);
                $item['file_size'] = $asset !== null ? (int) $asset->getFileSize() : 0;
            } catch (\Throwable) {
                $item['file_size'] = 0;
            }
        }

        return $items;
    }

    private function paginatedResponse(array $items, int $total, int $page, int $limit): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
        ];
    }
}
