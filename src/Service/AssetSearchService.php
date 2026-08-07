<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Service\Query\AssetRow;
use Oronts\AssetPilotBundle\Service\Query\AssetSortColumns;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\AuthorizedAssetPage;
use Oronts\AssetPilotBundle\Service\Query\IndexedAssetSize;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Psr\Log\LoggerInterface;

class AssetSearchService implements AssetSearchServiceInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AssetWorkspaceQueryScope $workspaceScope,
        private readonly AuthorizedAssetPage $authorizedPage,
        private readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    /**
     * @param array{q?: string, type?: string, folder?: string, objectId?: int, extension?: string, referenced?: string} $filters
     * @return array{items: array, total: ?int, page: int, pages: ?int, hasMore: bool, truncated: bool}
     */
    public function search(array $filters = [], int $page = 1, int $limit = 50, ?string $sort = null, ?string $order = null): array
    {
        [$sortColumn, $sortDir] = SortWhitelist::resolve($sort, $order, AssetSortColumns::MAP, AssetSortColumns::DEFAULT);

        try {
            $result = $this->authorizedPage->paginate(
                $page,
                $limit,
                exactTotal: function () use ($filters): int {
                    $countQb = $this->createCountQuery();
                    $this->applySearchFilters($countQb, $filters);
                    $this->workspaceScope->applyView($countQb, 'a', 'assetSearchCount');

                    return (int) $countQb->executeQuery()->fetchOne();
                },
                window: function (int $offset, int $limit) use ($filters, $sortColumn, $sortDir): array {
                    $qb = $this->createBaseQuery()->orderBy($sortColumn, $sortDir)->addOrderBy('a.id', $sortDir)->setFirstResult($offset)->setMaxResults($limit);
                    $this->applySearchFilters($qb, $filters);
                    $this->workspaceScope->applyView($qb, 'a', 'assetSearch');

                    return $this->hydrateItems($qb->executeQuery()->fetchAllAssociative());
                },
                assetIdOf: static fn (array $row): ?int => isset($row['id']) ? (int) $row['id'] : null,
            );

            return $this->paginatedResponse($result, $page, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: asset search failed: {error}', ['error' => $e->getMessage()]);

            return $this->paginatedResponse(['items' => [], 'total' => 0, 'hasMore' => false], $page, $limit);
        }
    }

    /**
     * Summarize specific assets by id (filename, path, type, size, locked), keyed by id, for enriching
     * id-only listings such as the duplicate report. Folders are excluded by the base query and unknown
     * ids are simply absent from the result.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function summarize(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            $query = $this->createBaseQuery()
                ->andWhere('a.id IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
            $this->workspaceScope->applyView($query, 'a', 'assetSummary');
            $rows = $this->hydrateItems($query->executeQuery()->fetchAllAssociative());

            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
            }

            return $byId;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: asset summarize failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function createBaseQuery(): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->select('a.id, a.path, a.filename, a.type, a.mimetype, a.creationDate as created_at, a.modificationDate as modified_at')
            ->addSelect('CASE WHEN lp.data = \'1\' THEN 1 ELSE 0 END as locked')
            ->from(PimcoreSchema::TABLE_ASSETS, 'a')
            ->leftJoin('a', PimcoreSchema::TABLE_PROPERTIES, 'lp', 'lp.cid = a.id AND lp.ctype = :lock_ctype AND lp.name = :lock_prop')
            ->where('a.type != :folder_type')
            ->setParameter('folder_type', PimcoreSchema::ASSET_TYPE_FOLDER)
            ->setParameter('lock_ctype', PimcoreSchema::ELEMENT_TYPE_ASSET)
            ->setParameter('lock_prop', $this->lockProperty);
        IndexedAssetSize::join($query);

        return $query;
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
            $qb->andWhere('(a.filename LIKE :q' . Like::CLAUSE . ' OR a.path LIKE :q' . Like::CLAUSE . ')')
                ->setParameter('q', '%' . Like::escape($q) . '%');
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('a.type = :type')->setParameter('type', $filters['type']);
        }

        if (!empty($filters['folder'])) {
            $folderPath = Like::escape(rtrim($filters['folder'], '/')) . '/%';
            $qb->andWhere('a.path LIKE :folder_path' . Like::CLAUSE)->setParameter('folder_path', $folderPath);
        }

        $objectId = (int) ($filters['objectId'] ?? 0);
        if ($objectId > 0) {
            $qb->andWhere($this->objectDependencyFilter())
                ->setParameter('objId', $objectId)
                ->setParameter('srcType', PimcoreSchema::ELEMENT_TYPE_OBJECT)
                ->setParameter('tgtType', PimcoreSchema::ELEMENT_TYPE_ASSET);
        }

        if (!empty($filters['extension'])) {
            $qb->andWhere('a.filename LIKE :ext' . Like::CLAUSE)
                ->setParameter('ext', '%.' . Like::escape(ltrim((string) $filters['extension'], '.')));
        }

        // Relations filter: keep only assets that are (or are not) referenced by any element. Uses the
        // same source-agnostic NOT EXISTS predicate as UnusedAssetFinder, so "unreferenced" here means
        // exactly what "unused" means on the Unused Assets tab (and is null-safe, unlike NOT IN).
        $referenced = $filters['referenced'] ?? '';
        if ($referenced === 'referenced' || $referenced === 'unreferenced') {
            $operator = $referenced === 'referenced' ? 'EXISTS' : 'NOT EXISTS';
            $qb->andWhere(sprintf(
                '%s (SELECT 1 FROM %s d WHERE d.targetid = a.id AND d.targettype = :refTgt)',
                $operator,
                PimcoreSchema::TABLE_DEPENDENCIES,
            ))
                ->setParameter('refTgt', PimcoreSchema::ELEMENT_TYPE_ASSET);
        }
    }

    private function hydrateItems(array $items): array
    {
        return array_map(AssetRow::normalize(...), $items);
    }

    /**
     * @param array{items: list<array<string, mixed>>, total: ?int, hasMore: bool, truncated?: bool} $result
     * @return array{items: array, total: ?int, page: int, pages: ?int, hasMore: bool, truncated: bool}
     */
    private function paginatedResponse(array $result, int $page, int $limit): array
    {
        return Pagination::envelope($result, $page, $limit);
    }

}
