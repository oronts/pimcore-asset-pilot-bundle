<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Psr\Log\LoggerInterface;

class AuditLogger implements AuditLoggerInterface
{
    public const string TABLE_NAME = 'asset_pilot_audit_log';

    private const string CACHE_KEY_STATS = 'asset_pilot.audit.stats';
    private const string CACHE_KEY_CLASS_BREAKDOWN = 'asset_pilot.audit.class_breakdown';

    private const array FILTERABLE = ['object_class', 'status', 'rule_name'];

    private const array SORTABLE = [
        'id' => 'id',
        'asset_id' => 'asset_id',
        'object_id' => 'object_id',
        'object_class' => 'object_class',
        'rule_name' => 'rule_name',
        'status' => 'status',
        'duration_ms' => 'duration_ms',
        'created_at' => 'created_at',
    ];

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
        protected readonly bool $enabled = true,
        protected readonly int $retentionDays = 90,
        protected readonly ?StatsCache $statsCache = null,
        protected readonly int $statsTtl = 0,
    ) {}

    /**
     * Serve a stats aggregate from the short-TTL cache, or compute it. The hot log() write path is left
     * untouched (no invalidation): the dashboard tolerates up to statsTtl seconds of staleness.
     *
     * @param callable():array<mixed> $compute
     *
     * @return array<mixed>
     */
    protected function cached(string $key, callable $compute): array
    {
        return $this->statsCache?->remember($key, $this->statsTtl, $compute) ?? $compute();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function log(MoveOperation $operation): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->connection->insert(self::TABLE_NAME, [
                'asset_id' => $operation->assetId,
                'asset_path_from' => $operation->sourcePath,
                'asset_path_to' => $operation->targetPath,
                'object_id' => $operation->objectId,
                'object_class' => $operation->objectClass,
                'rule_name' => $operation->ruleName,
                'trigger_type' => $operation->triggerType->value,
                'status' => $operation->status->value,
                'error_message' => $operation->errorMessage,
                'duration_ms' => $operation->durationMs,
                'user_id' => $operation->userId,
                'created_at' => $operation->createdAt->format('Y-m-d H:i:s'),
            ]);

            $this->logger->debug('Asset Pilot: logged audit entry for asset {assetId} ({status})', [
                'assetId' => $operation->assetId,
                'status' => $operation->status->value,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to write audit log: {error}', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getRecent(int $limit = 20, array $filters = []): array
    {
        $this->logger->debug('Asset Pilot: fetching recent audit entries (limit: {limit})', ['limit' => $limit]);

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('*')
                ->from(self::TABLE_NAME)
                ->orderBy('created_at', 'DESC')
                ->setMaxResults($limit);

            if (!empty($filters['object_class'])) {
                $qb->andWhere('object_class = :class')->setParameter('class', $filters['object_class']);
            }
            if (!empty($filters['status'])) {
                $qb->andWhere('status = :status')->setParameter('status', $filters['status']);
            }
            if (!empty($filters['rule_name'])) {
                $qb->andWhere('rule_name = :rule')->setParameter('rule', $filters['rule_name']);
            }
            if (!empty($filters['asset_id'])) {
                $qb->andWhere('asset_id = :assetId')->setParameter('assetId', (int) $filters['asset_id']);
            }
            if (!empty($filters['object_id'])) {
                $qb->andWhere('object_id = :objectId')->setParameter('objectId', (int) $filters['object_id']);
            }
            if (!empty($filters['since'])) {
                $qb->andWhere('created_at >= :since')->setParameter('since', $filters['since']);
            }

            return $qb->executeQuery()->fetchAllAssociative();
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to read audit log: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }
    }

    public function getStats(): array
    {
        $this->logger->debug('Asset Pilot: fetching audit stats');

        try {
            return $this->cached(self::CACHE_KEY_STATS, fn (): array => $this->computeStats());
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to fetch audit stats: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['by_class' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeStats(): array
    {
        $statusCounts = $this->connection->createQueryBuilder()
            ->select('status, COUNT(*) as count')
            ->from(self::TABLE_NAME)
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();

        $byClass = $this->connection->createQueryBuilder()
            ->select('object_class, COUNT(*) as count')
            ->from(self::TABLE_NAME)
            ->groupBy('object_class')
            ->orderBy('count', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $stats = ['by_class' => []];
        foreach ($statusCounts as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }
        foreach ($byClass as $row) {
            $stats['by_class'][$row['object_class']] = (int) $row['count'];
        }

        return $stats;
    }

    public function getDurationStats(): array
    {
        try {
            $row = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as cnt', 'AVG(duration_ms) as avg_ms', 'MIN(duration_ms) as min_ms', 'MAX(duration_ms) as max_ms')
                ->from(self::TABLE_NAME)
                ->where('status = :status')
                ->andWhere('duration_ms IS NOT NULL')
                ->setParameter('status', OperationStatus::Completed->value)
                ->executeQuery()
                ->fetchAssociative();

            return [
                'count' => (int) ($row['cnt'] ?? 0),
                'avgMs' => isset($row['avg_ms']) ? round((float) $row['avg_ms'], 1) : null,
                'minMs' => isset($row['min_ms']) ? (int) $row['min_ms'] : null,
                'maxMs' => isset($row['max_ms']) ? (int) $row['max_ms'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to read duration stats: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['count' => 0, 'avgMs' => null, 'minMs' => null, 'maxMs' => null];
        }
    }

    public function getPaginated(int $page = 1, int $limit = 20, array $filters = [], ?string $sort = null, ?string $order = null): array
    {
        $offset = ($page - 1) * $limit;
        [$sortColumn, $sortDir] = SortWhitelist::resolve($sort, $order, self::SORTABLE, 'created_at');

        $this->logger->debug('Asset Pilot: fetching paginated audit entries (page: {page}, limit: {limit})', [
            'page' => $page,
            'limit' => $limit,
        ]);

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('*')
                ->from(self::TABLE_NAME)
                ->orderBy($sortColumn, $sortDir)
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $countQb = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as total')
                ->from(self::TABLE_NAME);

            foreach ($filters as $key => $value) {
                if (!in_array($key, self::FILTERABLE, true) || $value === null || $value === '') {
                    continue;
                }
                $qb->andWhere("$key = :$key")->setParameter($key, $value);
                $countQb->andWhere("$key = :$key")->setParameter($key, $value);
            }

            $total = (int) $countQb->executeQuery()->fetchOne();
            $items = $qb->executeQuery()->fetchAllAssociative();

            return [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'pages' => (int) ceil($total / $limit),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to fetch paginated audit entries: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [
                'items' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 0,
            ];
        }
    }

    public function findById(int $id): ?array
    {
        try {
            $result = $this->connection->createQueryBuilder()
                ->select('*')
                ->from(self::TABLE_NAME)
                ->where('id = :id')
                ->setParameter('id', $id)
                ->executeQuery()
                ->fetchAssociative();

            return $result ?: null;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to find audit entry {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getStatsByRule(string $ruleName): array
    {
        try {
            $rows = $this->connection->createQueryBuilder()
                ->select('status, COUNT(*) as count')
                ->from(self::TABLE_NAME)
                ->where('rule_name = :rule')
                ->setParameter('rule', $ruleName)
                ->groupBy('status')
                ->executeQuery()
                ->fetchAllAssociative();

            $stats = [];
            foreach ($rows as $row) {
                $stats[$row['status']] = (int) $row['count'];
            }

            return $stats;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get stats for rule {rule}: {error}', [
                'rule' => $ruleName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function getClassBreakdown(): array
    {
        try {
            return $this->cached(self::CACHE_KEY_CLASS_BREAKDOWN, fn (): array => $this->computeClassBreakdown());
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get class breakdown: {error}', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function computeClassBreakdown(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('object_class, status, COUNT(*) as count')
            ->from(self::TABLE_NAME)
            ->groupBy('object_class, status')
            ->orderBy('object_class')
            ->executeQuery()
            ->fetchAllAssociative();

        $ruleRows = $this->connection->createQueryBuilder()
            ->select('object_class, COUNT(DISTINCT rule_name) as rule_count')
            ->from(self::TABLE_NAME)
            ->groupBy('object_class')
            ->executeQuery()
            ->fetchAllAssociative();

        $ruleCounts = [];
        foreach ($ruleRows as $row) {
            $ruleCounts[$row['object_class']] = (int) $row['rule_count'];
        }

        $breakdown = [];
        foreach ($rows as $row) {
            $class = $row['object_class'];
            if (!isset($breakdown[$class])) {
                $breakdown[$class] = [
                    'className' => $class,
                    'total' => 0,
                    OperationStatus::Completed->value => 0,
                    OperationStatus::Failed->value => 0,
                    OperationStatus::Skipped->value => 0,
                    'ruleCount' => $ruleCounts[$class] ?? 0,
                ];
            }
            $count = (int) $row['count'];
            $breakdown[$class]['total'] += $count;
            $breakdown[$class][$row['status']] = $count;
        }

        return array_values($breakdown);
    }

    public function getDistinctFailedObjects(array $filters = [], int $limit = 100): array
    {
        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('object_id, object_class, COUNT(*) as failures')
                ->from(self::TABLE_NAME)
                ->where('status = :status')
                ->setParameter('status', OperationStatus::Failed->value)
                ->groupBy('object_id, object_class')
                ->orderBy('failures', 'DESC')
                ->setMaxResults(max(1, $limit));

            if (!empty($filters['since'])) {
                $qb->andWhere('created_at >= :since')->setParameter('since', $filters['since']);
            }
            if (!empty($filters['rule_name'])) {
                $qb->andWhere('rule_name = :rule')->setParameter('rule', $filters['rule_name']);
            }
            if (!empty($filters['object_class'])) {
                $qb->andWhere('object_class = :class')->setParameter('class', $filters['object_class']);
            }

            return array_map(static fn (array $row): array => [
                'object_id' => (int) $row['object_id'],
                'object_class' => (string) $row['object_class'],
                'failures' => (int) $row['failures'],
            ], $qb->executeQuery()->fetchAllAssociative());
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to read failed-operation objects: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * Keyset-paginated export cursor: yields rows newest-first in bounded pages so a full audit export
     * streams without ever loading the whole table into memory. The (status|object_class, created_at)
     * composite indexes back the filtered cursor.
     *
     * @param array<string, mixed> $filters
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateForExport(array $filters = [], int $chunkSize = 1000): \Generator
    {
        $chunkSize = max(1, $chunkSize);
        $cursor = null;

        while (true) {
            $rows = $this->fetchExportPage($filters, $chunkSize, $cursor);
            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            if (count($rows) < $chunkSize) {
                return;
            }

            $last = $rows[array_key_last($rows)];
            $cursor = ['created_at' => (string) $last['created_at'], 'id' => (int) $last['id']];
        }
    }

    /**
     * @param array<string, mixed>                  $filters
     * @param array{created_at: string, id: int}|null $cursor
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchExportPage(array $filters, int $chunkSize, ?array $cursor): array
    {
        return $this->exportPageQuery($filters, $chunkSize, $cursor)->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed>                  $filters
     * @param array{created_at: string, id: int}|null $cursor
     */
    protected function exportPageQuery(array $filters, int $chunkSize, ?array $cursor): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($chunkSize);

        foreach (self::FILTERABLE as $key) {
            if (!empty($filters[$key])) {
                $qb->andWhere("$key = :$key")->setParameter($key, $filters[$key]);
            }
        }

        if ($cursor !== null) {
            $qb->andWhere('(created_at < :cursorAt OR (created_at = :cursorAt AND id < :cursorId))')
                ->setParameter('cursorAt', $cursor['created_at'])
                ->setParameter('cursorId', $cursor['id']);
        }

        return $qb;
    }

    public function cleanup(int $retentionDays): int
    {
        $this->logger->info('Asset Pilot: starting audit cleanup for entries older than {days} days', [
            'days' => $retentionDays,
        ]);

        try {
            $cutoff = (new \DateTimeImmutable())->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');

            $deleted = $this->connection->createQueryBuilder()
                ->delete(self::TABLE_NAME)
                ->where('created_at < :cutoff')
                ->setParameter('cutoff', $cutoff)
                ->executeStatement();

            $this->logger->info('Asset Pilot: cleaned up {count} audit entries older than {days} days', [
                'count' => $deleted,
                'days' => $retentionDays,
            ]);

            return $deleted;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: audit cleanup failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return 0;
        }
    }
}
