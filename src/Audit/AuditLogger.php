<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Psr\Log\LoggerInterface;

class AuditLogger implements AuditWriterInterface, AuditQueryInterface, AuditExportInterface, AuditRetentionInterface
{
    private const string CACHE_KEY_STATS = 'asset_pilot.audit.stats';
    private const string CACHE_KEY_CLASS_BREAKDOWN = 'asset_pilot.audit.class_breakdown';

    private const array FILTERABLE = ['object_class', 'status', 'rule_name'];

    private const array SORTABLE = [
        'id' => 'audit.id',
        'asset_id' => 'audit.asset_id',
        'object_id' => 'audit.object_id',
        'object_class' => 'audit.object_class',
        'rule_name' => 'audit.rule_name',
        'status' => 'audit.status',
        'duration_ms' => 'audit.duration_ms',
        'created_at' => 'audit.created_at',
    ];

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
        protected readonly ElementAuthorizationInterface $authorization,
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

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function log(MoveOperation $operation): void
    {
        try {
            $this->connection->insert(Installer::TABLE_AUDIT_LOG, $this->operationRow($operation));

            $this->logger->debug('Asset Pilot: logged audit entry for asset {assetId} ({status})', [
                'assetId' => $operation->assetId,
                'status' => $operation->status->value,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to write audit log: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function operationRow(MoveOperation $operation): array
    {
        $createdAt = $operation->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return [
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
            'created_at' => $createdAt,
            'operation_kind' => OperationKind::Move->value,
            'actor_type' => $operation->userId === null ? $this->authorization->currentActor()->type->value : ActorType::User->value,
            'parent_audit_id' => null,
            'intent_payload' => null,
            'schema_version' => 1,
            'updated_at' => $createdAt,
            'committed_at' => in_array($operation->status, [OperationStatus::Completed, OperationStatus::CompletedWithObserverError], true) ? $createdAt : null,
        ];
    }

    public function getRecent(int $limit = 20, array $filters = []): array
    {
        $this->logger->debug('Asset Pilot: fetching recent audit entries (limit: {limit})', ['limit' => $limit]);

        try {
            $scope = $this->visibilityScope();
            $qb = $this->connection->createQueryBuilder()
                ->select('audit.*')
                ->from(Installer::TABLE_AUDIT_LOG, 'audit')
                ->orderBy('audit.created_at', 'DESC')
                ->addOrderBy('audit.id', 'DESC')
                ->setMaxResults(max(1, $limit));
            $this->applyVisibilityScope($qb, $scope);

            if (!empty($filters['object_class'])) {
                $qb->andWhere('audit.object_class = :class')->setParameter('class', $filters['object_class']);
            }
            if (!empty($filters['status'])) {
                $qb->andWhere('audit.status = :status')->setParameter('status', $filters['status']);
            }
            if (!empty($filters['rule_name'])) {
                $qb->andWhere('audit.rule_name = :rule')->setParameter('rule', $filters['rule_name']);
            }
            if (!empty($filters['asset_id'])) {
                $qb->andWhere('audit.asset_id = :assetId')->setParameter('assetId', (int) $filters['asset_id']);
            }
            if (!empty($filters['object_id'])) {
                $qb->andWhere('audit.object_id = :objectId')->setParameter('objectId', (int) $filters['object_id']);
            }
            if (!empty($filters['since'])) {
                $qb->andWhere('audit.created_at >= :since')->setParameter('since', $filters['since']);
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
            return !$this->requiresWorkspaceFiltering()
                ? $this->cached(self::CACHE_KEY_STATS, fn (): array => $this->computeStats())
                : $this->computeStats();
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
        $scope = $this->visibilityScope();
        $statusQuery = $this->connection->createQueryBuilder()
            ->select('audit.status, COUNT(*) as count')
            ->from(Installer::TABLE_AUDIT_LOG, 'audit')
            ->groupBy('audit.status');
        $this->applyVisibilityScope($statusQuery, $scope);
        $statusCounts = $statusQuery->executeQuery()->fetchAllAssociative();

        $classQuery = $this->connection->createQueryBuilder()
            ->select('audit.object_class, COUNT(*) as count')
            ->from(Installer::TABLE_AUDIT_LOG, 'audit')
            ->groupBy('audit.object_class')
            ->orderBy('count', 'DESC');
        $this->applyVisibilityScope($classQuery, $scope);
        $byClass = $classQuery->executeQuery()->fetchAllAssociative();

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
            $qb = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as cnt', 'AVG(duration_ms) as avg_ms', 'MIN(duration_ms) as min_ms', 'MAX(duration_ms) as max_ms')
                ->from(Installer::TABLE_AUDIT_LOG, 'audit')
                ->where('audit.status IN (:statuses)')
                ->andWhere('audit.duration_ms IS NOT NULL')
                ->setParameter('statuses', [OperationStatus::Completed->value, OperationStatus::CompletedWithObserverError->value], ArrayParameterType::STRING);
            $this->applyVisibilityScope($qb, $this->visibilityScope());
            $row = $qb->executeQuery()->fetchAssociative();

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
            $scope = $this->visibilityScope();
            $qb = $this->connection->createQueryBuilder()
                ->select('audit.*')
                ->from(Installer::TABLE_AUDIT_LOG, 'audit')
                ->orderBy($sortColumn, $sortDir)
                ->setFirstResult($offset)
                ->setMaxResults($limit);
            if ($sortColumn !== 'audit.id') {
                $qb->addOrderBy('audit.id', $sortDir);
            }
            $this->applyVisibilityScope($qb, $scope);

            $countQb = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as total')
                ->from(Installer::TABLE_AUDIT_LOG, 'audit');
            $this->applyVisibilityScope($countQb, $scope);

            foreach ($filters as $key => $value) {
                if (!in_array($key, self::FILTERABLE, true) || $value === null || $value === '') {
                    continue;
                }
                $qb->andWhere("audit.$key = :$key")->setParameter($key, $value);
                $countQb->andWhere("audit.$key = :$key")->setParameter($key, $value);
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
            $qb = $this->connection->createQueryBuilder()
                ->select('audit.*')
                ->from(Installer::TABLE_AUDIT_LOG, 'audit')
                ->where('audit.id = :id')
                ->setParameter('id', $id);
            $this->applyVisibilityScope($qb, $this->visibilityScope());
            $result = $qb->executeQuery()->fetchAssociative();

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
            $qb = $this->connection->createQueryBuilder()
                ->select('audit.status, COUNT(*) as count')
                ->from(Installer::TABLE_AUDIT_LOG, 'audit')
                ->where('audit.rule_name = :rule')
                ->setParameter('rule', $ruleName)
                ->groupBy('audit.status');
            $this->applyVisibilityScope($qb, $this->visibilityScope());
            $rows = $qb->executeQuery()->fetchAllAssociative();

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
            return !$this->requiresWorkspaceFiltering()
                ? $this->cached(self::CACHE_KEY_CLASS_BREAKDOWN, fn (): array => $this->computeClassBreakdown())
                : $this->computeClassBreakdown();
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
        $scope = $this->visibilityScope();
        $breakdownQuery = $this->connection->createQueryBuilder()
            ->select('audit.object_class, audit.status, COUNT(*) as count')
            ->from(Installer::TABLE_AUDIT_LOG, 'audit')
            ->groupBy('audit.object_class, audit.status')
            ->orderBy('audit.object_class');
        $this->applyVisibilityScope($breakdownQuery, $scope);
        $rows = $breakdownQuery->executeQuery()->fetchAllAssociative();

        $ruleQuery = $this->connection->createQueryBuilder()
            ->select('audit.object_class, COUNT(DISTINCT audit.rule_name) as rule_count')
            ->from(Installer::TABLE_AUDIT_LOG, 'audit')
            ->groupBy('audit.object_class');
        $this->applyVisibilityScope($ruleQuery, $scope);
        $ruleRows = $ruleQuery->executeQuery()->fetchAllAssociative();

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
                    OperationStatus::CompletedWithObserverError->value => 0,
                    OperationStatus::Failed->value => 0,
                    OperationStatus::Skipped->value => 0,
                    'ruleCount' => $ruleCounts[$class] ?? 0,
                ];
            }
            if (!in_array($row['status'], [
                OperationStatus::Completed->value,
                OperationStatus::CompletedWithObserverError->value,
                OperationStatus::Failed->value,
                OperationStatus::Skipped->value,
            ], true)) {
                continue;
            }
            $count = (int) $row['count'];
            $breakdown[$class]['total'] += $count;
            $breakdown[$class][$row['status']] = $count;
            if ($row['status'] === OperationStatus::CompletedWithObserverError->value) {
                $breakdown[$class][OperationStatus::Completed->value] += $count;
            }
        }

        return array_values($breakdown);
    }

    public function getDistinctFailedObjects(array $filters = [], int $limit = 100): array
    {
        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('audit.object_id, audit.object_class, COUNT(*) as failures')
                ->from(Installer::TABLE_AUDIT_LOG, 'audit')
                ->where('audit.status = :status')
                ->andWhere('EXISTS (SELECT 1 FROM objects replay_object WHERE replay_object.id = audit.object_id)')
                ->setParameter('status', OperationStatus::Failed->value)
                ->groupBy('audit.object_id, audit.object_class')
                ->orderBy('failures', 'DESC')
                ->addOrderBy('audit.object_id', 'ASC')
                ->setMaxResults(max(1, $limit));
            $this->applyVisibilityScope($qb, $this->visibilityScope());

            if (!empty($filters['since'])) {
                $qb->andWhere('audit.created_at >= :since')->setParameter('since', $filters['since']);
            }
            if (!empty($filters['rule_name'])) {
                $qb->andWhere('audit.rule_name = :rule')->setParameter('rule', $filters['rule_name']);
            }
            if (array_key_exists('object_ids', $filters)) {
                $objectIds = array_values(array_unique(array_filter(
                    array_map('intval', is_array($filters['object_ids']) ? $filters['object_ids'] : []),
                    static fn (int $id): bool => $id > 0,
                )));
                if ($objectIds === []) {
                    return [];
                }
                $qb->andWhere('audit.object_id IN (:objectIds)')->setParameter('objectIds', $objectIds, ArrayParameterType::INTEGER);
            }
            if (!empty($filters['object_class'])) {
                $qb->andWhere('audit.object_class = :class')->setParameter('class', $filters['object_class']);
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

            yield from $rows;

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
            ->select('audit.*')
            ->from(Installer::TABLE_AUDIT_LOG, 'audit')
            ->orderBy('audit.created_at', 'DESC')
            ->addOrderBy('audit.id', 'DESC')
            ->setMaxResults($chunkSize);
        $this->applyVisibilityScope($qb, $this->visibilityScope());

        foreach (self::FILTERABLE as $key) {
            if (!empty($filters[$key])) {
                $qb->andWhere("audit.$key = :$key")->setParameter($key, $filters[$key]);
            }
        }

        if ($cursor !== null) {
            $qb->andWhere('(audit.created_at < :cursorAt OR (audit.created_at = :cursorAt AND audit.id < :cursorId))')
                ->setParameter('cursorAt', $cursor['created_at'])
                ->setParameter('cursorId', $cursor['id']);
        }

        return $qb;
    }

    /**
     * @return array{actorId: int, permissionIds: list<int>}|false|null
     */
    private function visibilityScope(): array|false|null
    {
        if (!$this->requiresWorkspaceFiltering()) {
            return null;
        }

        $actor = $this->authorization->currentActor();
        if ($actor->type !== ActorType::User || $actor->userId === null) {
            return false;
        }

        $account = $this->connection->createQueryBuilder()
            ->select('admin', 'active', 'roles')
            ->from('users')
            ->where('id = :actorId')
            ->setParameter('actorId', $actor->userId)
            ->executeQuery()
            ->fetchAssociative();
        if (!$account || !(bool) $account['active']) {
            return false;
        }

        if ((bool) $account['admin']) {
            return null;
        }

        if (!$this->authorization->hasGlobalPermission('assets') || !$this->authorization->hasGlobalPermission('objects')) {
            return false;
        }

        $roleIds = array_filter(
            array_map('intval', explode(',', (string) ($account['roles'] ?? ''))),
            static fn (int $roleId): bool => $roleId > 0,
        );

        return [
            'actorId' => $actor->userId,
            'permissionIds' => array_values(array_unique([$actor->userId, ...$roleIds])),
        ];
    }

    private function requiresWorkspaceFiltering(): bool
    {
        return $this->authorization->currentActor()->type !== ActorType::System;
    }

    /** @param array{actorId: int, permissionIds: list<int>}|false|null $scope */
    private function applyVisibilityScope(QueryBuilder $qb, array|false|null $scope): void
    {
        if ($scope === null) {
            return;
        }

        if ($scope === false) {
            $qb->andWhere('1 = 0');

            return;
        }

        $currentAssetPath = $this->connection->getDatabasePlatform()->getConcatExpression('current_asset.path', 'current_asset.filename');
        $currentObjectPath = $this->connection->getDatabasePlatform()->getConcatExpression('current_object.path', 'current_object.key');
        $assetExists = 'EXISTS (SELECT 1 FROM assets current_asset WHERE current_asset.id = audit.asset_id)';
        $objectExists = 'EXISTS (SELECT 1 FROM objects current_object WHERE current_object.id = audit.object_id)';
        $assetPath = "(SELECT $currentAssetPath FROM assets current_asset WHERE current_asset.id = audit.asset_id)";
        $objectPath = "(SELECT $currentObjectPath FROM objects current_object WHERE current_object.id = audit.object_id)";
        $currentAssetAllowed = $this->workspaceAllows('users_workspaces_asset', $assetPath, 'asset_allow', 'asset_closer');
        $sourceAssetAllowed = $this->workspaceAllows('users_workspaces_asset', 'audit.asset_path_from', 'source_allow', 'source_closer');
        $targetAssetAllowed = $this->workspaceAllows('users_workspaces_asset', 'audit.asset_path_to', 'target_allow', 'target_closer');
        $currentObjectAllowed = $this->workspaceAllows('users_workspaces_object', $objectPath, 'object_allow', 'object_closer');

        $qb->andWhere("(($assetExists AND $currentAssetAllowed) OR (NOT $assetExists AND ($sourceAssetAllowed OR $targetAssetAllowed)))")
            ->andWhere("(NOT $objectExists OR $currentObjectAllowed)")
            ->setParameter('workspaceActorId', $scope['actorId'])
            ->setParameter('workspaceUserIds', $scope['permissionIds'], ArrayParameterType::INTEGER);
    }

    private function workspaceAllows(string $table, string $path, string $allowedAlias, string $closerAlias): string
    {
        $allowedPathMatches = $this->pathIsWithinWorkspace($path, $allowedAlias);
        $closerPathMatches = $this->pathIsWithinWorkspace($path, $closerAlias);

        return <<<SQL
            EXISTS (
                SELECT 1 FROM $table $allowedAlias
                WHERE $allowedAlias.userId IN (:workspaceUserIds)
                  AND $allowedAlias.view = 1
                  AND $allowedPathMatches
                  AND NOT EXISTS (
                      SELECT 1 FROM $table $closerAlias
                      WHERE $closerAlias.userId IN (:workspaceUserIds)
                        AND $closerPathMatches
                        AND (
                            LENGTH($closerAlias.cpath) > LENGTH($allowedAlias.cpath)
                            OR (
                                $closerAlias.cpath = $allowedAlias.cpath
                                AND $closerAlias.userId = :workspaceActorId
                                AND $allowedAlias.userId <> :workspaceActorId
                            )
                        )
                  )
            )
            SQL;
    }

    private function pathIsWithinWorkspace(string $path, string $workspaceAlias): string
    {
        $childPrefix = $this->connection->getDatabasePlatform()->getConcatExpression("$workspaceAlias.cpath", $this->connection->quote('/'));

        return "($workspaceAlias.cpath = '/' OR $path = $workspaceAlias.cpath OR SUBSTRING($path, 1, LENGTH($workspaceAlias.cpath) + 1) = $childPrefix)";
    }

    public function cleanup(int $retentionDays): int
    {
        $this->logger->info('Asset Pilot: starting audit cleanup for entries older than {days} days', [
            'days' => $retentionDays,
        ]);

        try {
            $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');

            $deleted = 0;
            while (($ids = $this->cleanupCandidates($cutoff)) !== []) {
                $deleted += $this->connection->transactional(function () use ($ids): int {
                    $this->connection->createQueryBuilder()
                        ->delete(Installer::TABLE_OPERATION_DELIVERY)
                        ->where('operation_id IN (:operationIds)')
                        ->setParameter('operationIds', $ids, ArrayParameterType::INTEGER)
                        ->executeStatement();

                    return $this->connection->createQueryBuilder()
                        ->delete(Installer::TABLE_AUDIT_LOG)
                        ->where('id IN (:auditIds)')
                        ->setParameter('auditIds', $ids, ArrayParameterType::INTEGER)
                        ->executeStatement();
                });
            }

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

    /** @return list<int> */
    private function cleanupCandidates(string $cutoff): array
    {
        $unresolved = array_map(
            static fn (OperationDeliveryStatus $status): string => $status->value,
            array_filter(OperationDeliveryStatus::cases(), static fn (OperationDeliveryStatus $status): bool => $status->isUnresolved()),
        );
        $rows = $this->connection->createQueryBuilder()
            ->select('audit.id')
            ->from(Installer::TABLE_AUDIT_LOG, 'audit')
            ->where('audit.created_at < :cutoff')
            ->andWhere('audit.status NOT IN (:protectedStatuses)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' delivery WHERE delivery.operation_id = audit.id AND delivery.status IN (:unresolvedDeliveryStatuses))')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('protectedStatuses', [OperationStatus::InProgress->value, OperationStatus::RecoveryRequired->value], ArrayParameterType::STRING)
            ->setParameter('unresolvedDeliveryStatuses', $unresolved, ArrayParameterType::STRING)
            ->orderBy('audit.id', 'ASC')
            ->setMaxResults(1000)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }
}
