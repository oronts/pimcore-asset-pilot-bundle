<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Service\Query\AssetSortColumns;
use Oronts\AssetPilotBundle\Service\Query\AssetStorageSize;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Psr\Log\LoggerInterface;

class AssetSearchService implements AssetSearchServiceInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
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
            $depFilter = $this->objectDependencyFilter();

            $qb = $this->createBaseQuery()
                ->andWhere($depFilter)
                ->setParameter('objId', $objectId)
                ->setParameter('srcType', PimcoreSchema::ELEMENT_TYPE_OBJECT)
                ->setParameter('tgtType', PimcoreSchema::ELEMENT_TYPE_ASSET)
                ->orderBy($sortColumn, $sortDir)
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $countQb = $this->createCountQuery()
                ->andWhere($depFilter)
                ->setParameter('objId', $objectId)
                ->setParameter('srcType', PimcoreSchema::ELEMENT_TYPE_OBJECT)
                ->setParameter('tgtType', PimcoreSchema::ELEMENT_TYPE_ASSET);

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
            ->from(PimcoreSchema::TABLE_ASSETS, 'a')
            ->leftJoin('a', PimcoreSchema::TABLE_PROPERTIES, 'lp', 'lp.cid = a.id AND lp.ctype = :lock_ctype AND lp.name = :lock_prop')
            ->where('a.type != :folder_type')
            ->setParameter('folder_type', PimcoreSchema::ASSET_TYPE_FOLDER)
            ->setParameter('lock_ctype', PimcoreSchema::ELEMENT_TYPE_ASSET)
            ->setParameter('lock_prop', $this->lockProperty);
    }

    private function createCountQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('COUNT(*) as total')
            ->from(PimcoreSchema::TABLE_ASSETS, 'a')
            ->where('a.type != :folder_type')
            ->setParameter('folder_type', PimcoreSchema::ASSET_TYPE_FOLDER);
    }

    private function objectDependencyFilter(): string
    {
        return sprintf(
            'a.id IN (SELECT d.targetid FROM %s d WHERE d.sourceid = :objId AND d.sourcetype = :srcType AND d.targettype = :tgtType)',
            PimcoreSchema::TABLE_DEPENDENCIES,
        );
    }

    private function applySearchFilters(QueryBuilder $qb, array $filters): void
    {
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $qb->andWhere('(a.filename LIKE :q OR a.path LIKE :q)')
                ->setParameter('q', '%' . Like::escape($q) . '%');
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('a.type = :type')->setParameter('type', $filters['type']);
        }

        if (!empty($filters['folder'])) {
            $folderPath = Like::escape(rtrim($filters['folder'], '/')) . '/%';
            $qb->andWhere('a.path LIKE :folder_path')->setParameter('folder_path', $folderPath);
        }

        $objectId = (int) ($filters['objectId'] ?? 0);
        if ($objectId > 0) {
            $qb->andWhere($this->objectDependencyFilter())
                ->setParameter('objId', $objectId)
                ->setParameter('srcType', PimcoreSchema::ELEMENT_TYPE_OBJECT)
                ->setParameter('tgtType', PimcoreSchema::ELEMENT_TYPE_ASSET);
        }
    }

    private function hydrateItems(array $items): array
    {
        foreach ($items as &$item) {
            $item['created_at'] = $item['created_at'] ? date('Y-m-d H:i:s', (int) $item['created_at']) : null;
            $item['modified_at'] = $item['modified_at'] ? date('Y-m-d H:i:s', (int) $item['modified_at']) : null;
            $item['full_path'] = rtrim($item['path'] ?? '', '/') . '/' . ($item['filename'] ?? '');
            $item['locked'] = (bool) ($item['locked'] ?? false);
            $item['file_size'] = $this->fileSize($item['full_path']);
        }

        return $items;
    }

    protected function fileSize(string $fullPath): int
    {
        return AssetStorageSize::bytes($fullPath);
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
