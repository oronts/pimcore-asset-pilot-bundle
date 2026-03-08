<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Psr\Log\LoggerInterface;

class AuditLogger
{
    public const string TABLE_NAME = 'asset_pilot_audit_log';

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
        protected readonly bool $enabled = true,
        protected readonly int $retentionDays = 90,
    ) {}

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

            if (!empty($filters['class'])) {
                $qb->andWhere('object_class = :class')->setParameter('class', $filters['class']);
            }
            if (!empty($filters['status'])) {
                $qb->andWhere('status = :status')->setParameter('status', $filters['status']);
            }
            if (!empty($filters['rule_name'])) {
                $qb->andWhere('rule_name = :rule')->setParameter('rule', $filters['rule_name']);
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
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to fetch audit stats: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['by_class' => []];
        }
    }

    public function getPaginated(int $page = 1, int $limit = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $this->logger->debug('Asset Pilot: fetching paginated audit entries (page: {page}, limit: {limit})', [
            'page' => $page,
            'limit' => $limit,
        ]);

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('*')
                ->from(self::TABLE_NAME)
                ->orderBy('created_at', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $countQb = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as total')
                ->from(self::TABLE_NAME);

            foreach ($filters as $key => $value) {
                if ($value !== null && $value !== '') {
                    $qb->andWhere("$key = :$key")->setParameter($key, $value);
                    $countQb->andWhere("$key = :$key")->setParameter($key, $value);
                }
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
                        'completed' => 0,
                        'failed' => 0,
                        'skipped' => 0,
                        'ruleCount' => $ruleCounts[$class] ?? 0,
                    ];
                }
                $count = (int) $row['count'];
                $breakdown[$class]['total'] += $count;
                $breakdown[$class][$row['status']] = $count;
            }

            return array_values($breakdown);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get class breakdown: {error}', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public function getDistinctAssetsByRule(string $ruleName, int $page = 1, int $limit = 50, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('al.asset_id, MAX(al.asset_path_to) as last_path, MAX(al.created_at) as last_moved')
                ->addSelect('a.path, a.filename, a.type, a.mimetype, a.modificationDate as modified_at')
                ->from(self::TABLE_NAME, 'al')
                ->innerJoin('al', 'assets', 'a', 'al.asset_id = a.id')
                ->where('al.rule_name = :rule')
                ->andWhere('al.status = :status')
                ->setParameter('rule', $ruleName)
                ->setParameter('status', 'completed')
                ->groupBy('al.asset_id, a.path, a.filename, a.type, a.mimetype, a.modificationDate')
                ->orderBy('last_moved', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $countQb = $this->connection->createQueryBuilder()
                ->select('COUNT(DISTINCT al.asset_id) as total')
                ->from(self::TABLE_NAME, 'al')
                ->where('al.rule_name = :rule')
                ->andWhere('al.status = :status')
                ->setParameter('rule', $ruleName)
                ->setParameter('status', 'completed');

            if (!empty($filters['since'])) {
                $qb->andWhere('al.created_at >= :since')->setParameter('since', $filters['since']);
                $countQb->andWhere('al.created_at >= :since')->setParameter('since', $filters['since']);
            }

            if (!empty($filters['object_class'])) {
                $qb->andWhere('al.object_class = :class')->setParameter('class', $filters['object_class']);
                $countQb->andWhere('al.object_class = :class')->setParameter('class', $filters['object_class']);
            }

            $total = (int) $countQb->executeQuery()->fetchOne();
            $items = $qb->executeQuery()->fetchAllAssociative();

            foreach ($items as &$item) {
                $item['id'] = (int) $item['asset_id'];
                $item['modified_at'] = $item['modified_at'] ? date('Y-m-d H:i:s', (int) $item['modified_at']) : null;
                $item['full_path'] = rtrim($item['path'] ?? '', '/') . '/' . ($item['filename'] ?? '');
            }

            return [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get assets by rule: {error}', [
                'error' => $e->getMessage(),
            ]);

            return ['items' => [], 'total' => 0, 'page' => $page, 'pages' => 0];
        }
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
