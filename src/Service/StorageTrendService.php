<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\Query\ByteFormat;

/**
 * Storage-cost trend reporting: periodically snapshots the current unused-asset count and byte size
 * per type into an owned table, so the dashboard can show how unused storage moves over time. The
 * capture is a scan (no size column on the assets table) and runs on the maintenance schedule / CLI,
 * never on a request; reporting reads only the snapshot table.
 */
class StorageTrendService
{
    public const string TABLE = 'asset_pilot_storage_snapshot';

    public function __construct(
        protected readonly Connection $connection,
        protected readonly UnusedAssetFinderInterface $unusedAssetFinder,
    ) {}

    /**
     * @return array{capturedAt: string, types: int, totalCount: int, totalSize: int}
     */
    public function capture(): array
    {
        $stats = $this->unusedAssetFinder->getUnusedStats();
        $capturedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $types = 0;
        foreach ($stats['byType'] ?? [] as $row) {
            $this->insertSnapshot($capturedAt, (string) $row['type'], (int) $row['count'], (int) $row['total_size']);
            ++$types;
        }

        return [
            'capturedAt' => $capturedAt,
            'types' => $types,
            'totalCount' => (int) ($stats['totalCount'] ?? 0),
            'totalSize' => (int) ($stats['totalSize'] ?? 0),
        ];
    }

    /**
     * The unused-storage series, newest-first. A type returns its own series; null sums all types
     * per snapshot.
     *
     * @return list<array{capturedAt: string, count: int, size: int}>
     */
    public function trend(?string $type = null, int $limit = 90): array
    {
        return array_map(
            static fn (array $row): array => [
                'capturedAt' => (string) $row['captured_at'],
                'count' => (int) $row['unused_count'],
                'size' => (int) $row['unused_size'],
            ],
            $this->fetchTrendRows($type, max(1, $limit)),
        );
    }

    /**
     * The most recent snapshot rendered as unused-asset stats (same shape as
     * UnusedAssetFinderInterface::getUnusedStats), or null if nothing has been captured yet. Lets the
     * web endpoint serve a materialised result (two indexed reads, no filesystem) instead of cold-scanning
     * the catalog; keep the StorageSnapshotTask scheduled so it stays fresh.
     *
     * @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, byType: list<array{type: string, count: int, total_size: int}>}|null
     */
    public function latestUnusedStats(): ?array
    {
        $rows = $this->fetchLatestSnapshotRows();
        if ($rows === []) {
            return null;
        }

        $byType = [];
        $totalCount = 0;
        $totalSize = 0;
        foreach ($rows as $row) {
            $count = (int) $row['unused_count'];
            $size = (int) $row['unused_size'];
            $byType[] = ['type' => (string) $row['type'], 'count' => $count, 'total_size' => $size];
            $totalCount += $count;
            $totalSize += $size;
        }

        usort($byType, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'totalCount' => $totalCount,
            'totalSize' => $totalSize,
            'totalSizeFormatted' => ByteFormat::human($totalSize),
            'byType' => $byType,
        ];
    }

    /**
     * @return list<array{type: string, unused_count: int|string, unused_size: int|string}>
     */
    protected function fetchLatestSnapshotRows(): array
    {
        $latest = $this->connection->createQueryBuilder()
            ->select('MAX(captured_at)')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();

        if ($latest === null || $latest === false) {
            return [];
        }

        return $this->connection->createQueryBuilder()
            ->select('type', 'unused_count', 'unused_size')
            ->from(self::TABLE)
            ->where('captured_at = :capturedAt')
            ->setParameter('capturedAt', $latest)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    protected function insertSnapshot(string $capturedAt, string $type, int $count, int $size): void
    {
        $this->connection->insert(self::TABLE, [
            'captured_at' => $capturedAt,
            'type' => $type,
            'unused_count' => $count,
            'unused_size' => $size,
        ]);
    }

    /**
     * @return list<array{captured_at: string, unused_count: int|string, unused_size: int|string}>
     */
    protected function fetchTrendRows(?string $type, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->from(self::TABLE)
            ->orderBy('captured_at', 'DESC')
            ->setMaxResults($limit);

        if ($type !== null && $type !== '') {
            $qb->select('captured_at', 'unused_count', 'unused_size')
                ->where('type = :type')
                ->setParameter('type', $type);
        } else {
            $qb->select('captured_at', 'SUM(unused_count) AS unused_count', 'SUM(unused_size) AS unused_size')
                ->groupBy('captured_at');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
