<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\Query\AssetFilter;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Byte-identical duplicate detection. Pimcore stores no checksum or filesize column on the `assets`
 * table (the MD5 lives in the customSettings JSON, the size is a storage stat), so there is no
 * SQL `GROUP BY hash` to run against it directly. This keeps an owned index — asset id, content
 * hash, size — populated by a bounded, paged scan; duplicate grouping is then a single indexed
 * GROUP BY over that table, so a report never re-hashes the catalog and never blocks.
 *
 * Detection only: merging duplicates (re-pointing references, deleting the copy) is a separate,
 * guarded operation.
 */
class DuplicateDetectionService
{
    public const string TABLE = 'asset_pilot_checksum';

    /** Assets indexed per page during a scan — bounds the work and memory of one batch. */
    private const int SCAN_BATCH = 200;

    /** Cap on the asset ids returned per duplicate group (drill-down fetches the rest on demand). */
    private const int IDS_PER_GROUP = 100;

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Compute and store the content hash for up to $limit matching assets (paged in batches, so a
     * large catalog never loads at once). Re-running re-indexes (upsert), so it is idempotent and
     * picks up changed binaries.
     *
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return array{scanned: int, indexed: int, skipped: int}
     */
    public function index(array $filters = [], int $limit = 1000): array
    {
        $limit = max(1, $limit);
        $scanned = 0;
        $indexed = 0;
        $skipped = 0;
        $offset = 0;

        while ($scanned < $limit) {
            $take = min(self::SCAN_BATCH, $limit - $scanned);
            $ids = $this->listAssetIds($filters, $offset, $take);
            if ($ids === []) {
                break;
            }

            foreach ($ids as $id) {
                ++$scanned;
                $asset = $this->loadAsset((int) $id);
                if ($asset === null || $asset instanceof Asset\Folder) {
                    ++$skipped;
                    continue;
                }
                $checksum = $this->checksumOf($asset);
                if ($checksum === '') {
                    // No hash available (e.g. the storage adapter could not provide one) — never group
                    // empties together as if they were equal.
                    ++$skipped;
                    continue;
                }
                $this->upsert((int) $id, $checksum, $this->fileSizeOf($asset));
                ++$indexed;
            }

            $offset += count($ids);
            if (count($ids) < $take) {
                break;
            }
        }

        return ['scanned' => $scanned, 'indexed' => $indexed, 'skipped' => $skipped];
    }

    /**
     * @param int         $minCopies minimum copies a group must have (clamped to >= 2)
     * @param string|null $type      restrict to groups whose assets are of this type
     *
     * @return list<DuplicateGroup> content hashes shared by at least $minCopies indexed assets, paged
     */
    public function findDuplicates(int $page = 1, int $limit = 50, int $minCopies = 2, ?string $type = null): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $groups = [];
        foreach ($this->fetchDuplicateRows(($page - 1) * $limit, $limit, $minCopies, $type) as $row) {
            $checksum = (string) $row['checksum'];
            $groups[] = new DuplicateGroup(
                $checksum,
                (int) $row['file_size'],
                (int) $row['cnt'],
                $this->assetIdsForChecksum($checksum, self::IDS_PER_GROUP),
            );
        }

        return $groups;
    }

    public function countDuplicateGroups(int $minCopies = 2, ?string $type = null): int
    {
        try {
            // INNER JOIN assets so a deleted asset's stale index row is not counted (no ghost groups).
            // Same filters as fetchDuplicateRows, so the count matches the paged list.
            $inner = $this->groupQuery($minCopies, $type)->select('c.checksum');

            return (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM (%s) AS grouped', $inner->getSQL()),
                $inner->getParameters(),
                $inner->getParameterTypes(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to count duplicate groups: {error}', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * The duplicate group for one content hash (live assets only), or null when fewer than two live
     * assets share it. The id list is capped at {@see self::IDS_PER_GROUP}, so a merge of a very large
     * group is bounded and simply re-run.
     */
    public function groupForChecksum(string $checksum): ?DuplicateGroup
    {
        if ($checksum === '') {
            return null;
        }

        try {
            $ids = $this->assetIdsForChecksum($checksum, self::IDS_PER_GROUP);
            if (count($ids) < 2) {
                return null;
            }

            $fileSize = (int) $this->connection->createQueryBuilder()
                ->select('MIN(c.file_size)')
                ->from(self::TABLE, 'c')
                ->innerJoin('c', PimcoreSchema::TABLE_ASSETS, 'a', 'a.id = c.asset_id')
                ->where('c.checksum = :checksum')
                ->setParameter('checksum', $checksum)
                ->executeQuery()
                ->fetchOne();

            return new DuplicateGroup($checksum, $fileSize, count($ids), $ids);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to load duplicate group for a checksum: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The duplicate group one asset belongs to, via its indexed content hash, or null when it is not
     * indexed yet or has no byte-identical siblings. The targeted "does THIS asset have copies?" check.
     */
    public function groupForAsset(int $assetId): ?DuplicateGroup
    {
        $checksum = $this->indexedChecksumFor($assetId);

        return $checksum === null ? null : $this->groupForChecksum($checksum);
    }

    protected function indexedChecksumFor(int $assetId): ?string
    {
        try {
            $checksum = $this->connection->createQueryBuilder()
                ->select('checksum')
                ->from(self::TABLE)
                ->where('asset_id = :id')
                ->setParameter('id', $assetId)
                ->executeQuery()
                ->fetchOne();

            return $checksum === false ? null : (string) $checksum;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to read the indexed checksum for asset {id}: {error}', ['id' => $assetId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return list<int>
     */
    protected function listAssetIds(array $filters, int $offset, int $limit): array
    {
        [$condition, $params] = AssetFilter::condition($filters, excludeFolders: true);

        $listing = new Asset\Listing();
        $listing->setCondition($condition, $params);
        $listing->setOffset(max(0, $offset));
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    /**
     * The asset's content hash (Pimcore's MD5, Flysystem stream-hashed, cached in customSettings).
     * Returns '' when the storage adapter cannot provide one.
     */
    protected function checksumOf(Asset $asset): string
    {
        return $asset->getChecksum();
    }

    protected function fileSizeOf(Asset $asset): int
    {
        return $asset->getFileSize();
    }

    protected function upsert(int $assetId, string $checksum, int $fileSize): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (asset_id, checksum, file_size, indexed_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), file_size = VALUES(file_size), indexed_at = VALUES(indexed_at)',
                self::TABLE,
            ),
            [$assetId, $checksum, $fileSize, (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @return list<array{checksum: string, file_size: int|string, cnt: int|string}>
     */
    protected function fetchDuplicateRows(int $offset, int $limit, int $minCopies = 2, ?string $type = null): array
    {
        return $this->groupQuery($minCopies, $type)
            ->select('c.checksum', 'MIN(c.file_size) AS file_size', 'COUNT(*) AS cnt')
            ->orderBy('cnt', 'DESC')
            ->addOrderBy('c.checksum', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * The base GROUP BY query over the checksum index joined to live assets (so a deleted asset's
     * stale row never forms a ghost group), with the min-copies and optional type filters applied.
     * Shared by the paged list and the count so the two always agree.
     */
    protected function groupQuery(int $minCopies, ?string $type): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->from(self::TABLE, 'c')
            ->innerJoin('c', PimcoreSchema::TABLE_ASSETS, 'a', 'a.id = c.asset_id')
            ->groupBy('c.checksum')
            ->having('COUNT(*) >= :minCopies')
            ->setParameter('minCopies', max(2, $minCopies), ParameterType::INTEGER);

        if ($type !== null && $type !== '') {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        return $qb;
    }

    /**
     * @return list<int>
     */
    protected function assetIdsForChecksum(string $checksum, int $cap): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('c.asset_id')
            ->from(self::TABLE, 'c')
            ->innerJoin('c', PimcoreSchema::TABLE_ASSETS, 'a', 'a.id = c.asset_id')
            ->where('c.checksum = :checksum')
            ->setParameter('checksum', $checksum)
            ->orderBy('c.asset_id', 'ASC')
            ->setMaxResults($cap)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $ids);
    }
}
