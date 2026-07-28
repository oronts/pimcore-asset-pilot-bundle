<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use Psr\Log\LoggerInterface;

class StorageTrendService implements StorageTrendServiceInterface
{
    private const string STATUS_RUNNING = 'running';
    private const string STATUS_COMPLETED = 'completed';
    private const string STATUS_FAILED = 'failed';
    private const string CAPTURE_LOCK = 'maintenance:storage-snapshot';

    public function __construct(
        protected readonly Connection $connection,
        protected readonly UnusedAssetFinderInterface $unusedAssetFinder,
        protected readonly LoggerInterface $logger,
        protected readonly LoopGuard $loopGuard,
        protected readonly bool $enabled = true,
        protected readonly int $minimumIntervalSeconds = 3600,
        protected readonly int $retentionDays = 365,
    ) {}

    /** @return array{captured: bool, runId: int|null, capturedAt: string|null, types: int, totalCount: int, totalSize: int, unknownSizeCount: int, reason: string|null} */
    public function capture(bool $force = false): array
    {
        if (!$this->enabled) {
            return $this->skipped('Storage snapshot capture is disabled.');
        }
        if (!$this->loopGuard->acquireTarget(self::CAPTURE_LOCK)) {
            return $this->skipped('Another storage snapshot capture is running.');
        }

        try {
            if (!$force && $this->capturedRecently()) {
                return $this->skipped('A fresh storage snapshot already exists.');
            }

            $startedAt = $this->now();
            $runId = $this->beginRun($startedAt);
            try {
                $stats = $this->unusedAssetFinder->getUnusedStats();
                $rows = array_values($stats['byType'] ?? []);
                $this->connection->transactional(function () use ($runId, $startedAt, $rows, $stats): void {
                    foreach ($rows as $row) {
                        $this->insertSnapshot($runId, $startedAt, (string) $row['type'], (int) $row['count'], (int) $row['total_size'], (int) ($row['unknown_size_count'] ?? 0));
                    }
                    $this->completeRun($runId, (int) ($stats['totalCount'] ?? 0), (int) ($stats['totalSize'] ?? 0), (int) ($stats['unknownSizeCount'] ?? 0));
                });
                try {
                    $this->prune();
                } catch (\Throwable $e) {
                    $this->logger->warning('Asset Pilot: storage snapshot retention cleanup failed.', ['exception' => $e]);
                }

                return [
                    'captured' => true,
                    'runId' => $runId,
                    'capturedAt' => $startedAt,
                    'types' => count($rows),
                    'totalCount' => (int) ($stats['totalCount'] ?? 0),
                    'totalSize' => (int) ($stats['totalSize'] ?? 0),
                    'unknownSizeCount' => (int) ($stats['unknownSizeCount'] ?? 0),
                    'reason' => null,
                ];
            } catch (\Throwable $e) {
                $this->failRun($runId);
                $this->logger->error('Asset Pilot: storage snapshot capture failed.', ['run_id' => $runId, 'exception' => $e]);

                throw $e;
            }
        } finally {
            $this->loopGuard->releaseTarget(self::CAPTURE_LOCK);
        }
    }

    /** @return list<array{capturedAt: string, count: int, size: int, unknownSizeCount: int}> */
    public function trend(?string $type = null, int $limit = 90): array
    {
        return array_map(
            static fn (array $row): array => [
                'capturedAt' => (string) $row['captured_at'],
                'count' => (int) $row['unused_count'],
                'size' => (int) $row['unused_size'],
                'unknownSizeCount' => (int) $row['unknown_size_count'],
            ],
            $this->fetchTrendRows($type, max(1, $limit)),
        );
    }

    /** @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, unknownSizeCount: int, byType: list<array{type: string, count: int, total_size: int, unknown_size_count: int}>}|null */
    public function latestUnusedStats(): ?array
    {
        $run = $this->latestCompletedRun();
        if ($run === null) {
            return null;
        }

        $rows = $this->fetchSnapshotRows((int) $run['id']);
        $byType = array_map(static fn (array $row): array => [
            'type' => (string) $row['type'],
            'count' => (int) $row['unused_count'],
            'total_size' => (int) $row['unused_size'],
            'unknown_size_count' => (int) $row['unknown_size_count'],
        ], $rows);
        usort($byType, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $totalSize = (int) $run['total_size'];

        return [
            'totalCount' => (int) $run['total_count'],
            'totalSize' => $totalSize,
            'totalSizeFormatted' => ByteFormat::human($totalSize),
            'unknownSizeCount' => (int) $run['unknown_size_count'],
            'byType' => $byType,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function latestCompletedRun(): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'completed_at', 'total_count', 'total_size', 'unknown_size_count')
            ->from(Installer::TABLE_STORAGE_RUN)
            ->where('status = :status')
            ->setParameter('status', self::STATUS_COMPLETED)
            ->orderBy('completed_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /** @return list<array{type: string, unused_count: int|string, unused_size: int|string, unknown_size_count: int|string}> */
    protected function fetchSnapshotRows(int $runId): array
    {
        return $this->connection->createQueryBuilder()
            ->select('type', 'unused_count', 'unused_size', 'unknown_size_count')
            ->from(Installer::TABLE_STORAGE_SNAPSHOT)
            ->where('run_id = :runId')
            ->setParameter('runId', $runId)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    protected function insertSnapshot(int $runId, string $capturedAt, string $type, int $count, int $size, int $unknownSizeCount): void
    {
        $this->connection->insert(Installer::TABLE_STORAGE_SNAPSHOT, [
            'run_id' => $runId,
            'captured_at' => $capturedAt,
            'type' => $type,
            'unused_count' => $count,
            'unused_size' => $size,
            'unknown_size_count' => $unknownSizeCount,
        ]);
    }

    /** @return list<array{captured_at: string, unused_count: int|string, unused_size: int|string, unknown_size_count: int|string}> */
    protected function fetchTrendRows(?string $type, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->from(Installer::TABLE_STORAGE_RUN, 'r')
            ->where('r.status = :status')
            ->setParameter('status', self::STATUS_COMPLETED)
            ->orderBy('r.completed_at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit);

        if ($type !== null && $type !== '') {
            // One snapshot row per (run, type) via the UNIQUE(run_id, type) key, so this join never fans out.
            $qb->leftJoin('r', Installer::TABLE_STORAGE_SNAPSHOT, 's', 's.run_id = r.id AND s.type = :type')
                ->select('r.completed_at AS captured_at', 'COALESCE(s.unused_count, 0) AS unused_count', 'COALESCE(s.unused_size, 0) AS unused_size', 'COALESCE(s.unknown_size_count, 0) AS unknown_size_count')
                ->setParameter('type', $type);
        } else {
            // The overall trend reads the run totals directly: joining snapshots would fan out one point per type.
            $qb->select('r.completed_at AS captured_at', 'r.total_count AS unused_count', 'r.total_size AS unused_size', 'r.unknown_size_count');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    protected function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function beginRun(string $startedAt): int
    {
        $this->connection->insert(Installer::TABLE_STORAGE_RUN, [
            'started_at' => $startedAt,
            'completed_at' => null,
            'status' => self::STATUS_RUNNING,
            'total_count' => 0,
            'total_size' => 0,
            'unknown_size_count' => 0,
            'error_message' => null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function completeRun(int $runId, int $totalCount, int $totalSize, int $unknownSizeCount): void
    {
        $this->connection->update(Installer::TABLE_STORAGE_RUN, [
            'completed_at' => $this->now(),
            'status' => self::STATUS_COMPLETED,
            'total_count' => $totalCount,
            'total_size' => $totalSize,
            'unknown_size_count' => $unknownSizeCount,
            'error_message' => null,
        ], ['id' => $runId]);
    }

    private function failRun(int $runId): void
    {
        try {
            $this->connection->update(Installer::TABLE_STORAGE_RUN, [
                'completed_at' => $this->now(),
                'status' => self::STATUS_FAILED,
                'error_message' => 'Capture failed. See server logs.',
            ], ['id' => $runId]);
        } catch (\Throwable) {
        }
    }

    private function capturedRecently(): bool
    {
        if ($this->minimumIntervalSeconds <= 0) {
            return false;
        }
        $run = $this->latestCompletedRun();
        if ($run === null || empty($run['completed_at'])) {
            return false;
        }

        return (new \DateTimeImmutable((string) $run['completed_at'], new \DateTimeZone('UTC')))->getTimestamp() >= time() - $this->minimumIntervalSeconds;
    }

    private function prune(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('-%d days', $this->retentionDays))->format('Y-m-d H:i:s');
        $ids = array_map('intval', $this->connection->createQueryBuilder()
            ->select('id')
            ->from(Installer::TABLE_STORAGE_RUN)
            ->where('completed_at < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->executeQuery()
            ->fetchFirstColumn());
        if ($ids !== []) {
            $this->connection->transactional(function () use ($ids): void {
                $this->connection->createQueryBuilder()
                    ->delete(Installer::TABLE_STORAGE_SNAPSHOT)
                    ->where('run_id IN (:ids)')
                    ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                    ->executeStatement();
                $this->connection->createQueryBuilder()
                    ->delete(Installer::TABLE_STORAGE_RUN)
                    ->where('id IN (:ids)')
                    ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                    ->executeStatement();
            });
        }

    }

    /** @return array{captured: false, runId: null, capturedAt: null, types: 0, totalCount: 0, totalSize: 0, unknownSizeCount: 0, reason: string} */
    private function skipped(string $reason): array
    {
        return ['captured' => false, 'runId' => null, 'capturedAt' => null, 'types' => 0, 'totalCount' => 0, 'totalSize' => 0, 'unknownSizeCount' => 0, 'reason' => $reason];
    }
}
