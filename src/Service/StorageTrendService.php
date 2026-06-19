<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;

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
