<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;

final class OperationRunStore implements OperationRunStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * @param list<array{key: string, type: string, id?: int|null, fingerprint?: string|null, payload?: array<string, mixed>, state?: array<string, mixed>}> $items
     * @param array<string, mixed>                                                                                                             $request
     */
    public function create(string $kind, ActorContext $actor, array $items, array $request = [], ?string $retryOf = null): string
    {
        if ($kind === '' || $items === []) {
            throw new \InvalidArgumentException('Operation runs require a kind and at least one item.');
        }

        $runId = bin2hex(random_bytes(16));
        $now = $this->now();
        $this->connection->transactional(function () use ($runId, $kind, $actor, $items, $request, $retryOf, $now): void {
            $attempt = 1;
            if ($retryOf !== null) {
                $parentAttempt = $this->connection->fetchOne(
                    'SELECT attempt FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
                    [$retryOf],
                );
                if ($parentAttempt === false) {
                    throw new \InvalidArgumentException('The retry parent operation run does not exist.');
                }
                $attempt = (int) $parentAttempt + 1;
            }

            $this->connection->insert(Installer::TABLE_OPERATION_RUN, [
                'id' => $runId,
                'kind' => $kind,
                'actor_type' => $actor->type->value,
                'actor_user_id' => $actor->userId,
                'status' => OperationRunStatus::Queued->value,
                'total_count' => count($items),
                'processed_count' => 0,
                'succeeded_count' => 0,
                'skipped_count' => 0,
                'blocked_count' => 0,
                'failed_count' => 0,
                'attempt' => $attempt,
                'retry_of' => $retryOf,
                'request_payload' => $this->encode($request),
                'error_message' => null,
                'created_at' => $now,
                'started_at' => null,
                'updated_at' => $now,
                'completed_at' => null,
            ]);

            foreach ($items as $item) {
                if (($item['key'] ?? '') === '' || ($item['type'] ?? '') === '') {
                    throw new \InvalidArgumentException('Every operation run item requires a key and type.');
                }
                $this->connection->insert(Installer::TABLE_OPERATION_RUN_ITEM, [
                    'run_id' => $runId,
                    'item_key' => $item['key'],
                    'target_type' => $item['type'],
                    'target_id' => $item['id'] ?? null,
                    'fingerprint' => $item['fingerprint'] ?? null,
                    'payload' => $this->encode($item['payload'] ?? []),
                    'state_payload' => $this->encode($item['state'] ?? []),
                    'status' => OperationRunItemStatus::Queued->value,
                    'attempts' => 0,
                    'result_payload' => null,
                    'error_message' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'completed_at' => null,
                ]);
            }
        });

        return $runId;
    }

    public function start(string $runId): bool
    {
        $now = $this->now();

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET status = ?, started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ? AND status = ?',
            [OperationRunStatus::Running->value, $now, $now, $runId, OperationRunStatus::Queued->value],
        ) === 1;
    }

    public function resume(string $runId): bool
    {
        $now = $this->now();

        $updated = $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET status = ?, started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ? AND status IN (?, ?)',
            [OperationRunStatus::Running->value, $now, $now, $runId, OperationRunStatus::Queued->value, OperationRunStatus::Running->value],
        );
        if ($updated === 1) {
            return true;
        }

        return $this->connection->fetchOne(
            'SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
            [$runId],
        ) === OperationRunStatus::Running->value;
    }

    public function startItem(string $runId, string $itemKey): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, attempts = attempts + 1, updated_at = ? WHERE run_id = ? AND item_key = ? AND status = ? AND EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ? AND status = ?)',
            [OperationRunItemStatus::Running->value, $this->now(), $runId, $itemKey, OperationRunItemStatus::Queued->value, $runId, OperationRunStatus::Running->value],
        ) === 1;
    }

    public function resumeItem(string $runId, string $itemKey): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, attempts = attempts + 1, updated_at = ? WHERE run_id = ? AND item_key = ? AND status IN (?, ?) AND EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ? AND status = ?)',
            [OperationRunItemStatus::Running->value, $this->now(), $runId, $itemKey, OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value, $runId, OperationRunStatus::Running->value],
        ) === 1;
    }

    /** @param array<string, mixed> $state */
    public function updateItemState(string $runId, string $itemKey, array $state): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET state_payload = ?, updated_at = ? WHERE run_id = ? AND item_key = ? AND status IN (?, ?)',
            [$this->encode($state), $this->now(), $runId, $itemKey, OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value],
        ) === 1;
    }

    /** @param array<string, mixed> $result */
    public function completeItem(
        string $runId,
        string $itemKey,
        OperationRunItemStatus $status,
        array $result = [],
        ?string $error = null,
    ): bool {
        if (!$status->isTerminal()) {
            throw new \InvalidArgumentException('An operation run item can only complete with a terminal status.');
        }
        return $this->connection->transactional(function () use ($runId, $itemKey, $status, $result, $error): bool {
            $this->lockRun($runId);
            $now = $this->now();
            $updated = $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, result_payload = ?, error_message = ?, updated_at = ?, completed_at = ? WHERE run_id = ? AND item_key = ? AND status IN (?, ?)',
                [$status->value, $this->encode($result), $error, $now, $now, $runId, $itemKey, OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value],
            );
            if ($updated === 1) {
                $this->refreshCounts($runId);
            }

            return $updated === 1;
        });
    }

    public function requestCancellation(string $runId, ActorContext $actor): bool
    {
        [$where, $params] = $this->actorWhere($actor);

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET status = ?, updated_at = ? WHERE id = ? AND status IN (?, ?) AND ' . $where,
            [OperationRunStatus::CancelRequested->value, $this->now(), $runId, OperationRunStatus::Queued->value, OperationRunStatus::Running->value, ...$params],
        ) === 1;
    }

    public function isCancellationRequested(string $runId): bool
    {
        return $this->connection->fetchOne(
            'SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
            [$runId],
        ) === OperationRunStatus::CancelRequested->value;
    }

    public function finish(string $runId): OperationRunStatus
    {
        return $this->connection->transactional(function () use ($runId): OperationRunStatus {
            $this->lockRun($runId);
            $run = $this->connection->fetchAssociative(
                'SELECT status, total_count, processed_count, succeeded_count, skipped_count, blocked_count, failed_count FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
                [$runId],
            );
            if ($run === false) {
                throw new \RuntimeException('Operation run not found.');
            }

            $currentStatus = OperationRunStatus::from((string) $run['status']);
            if ($currentStatus->isTerminal()) {
                $this->refreshCounts($runId);
                return $currentStatus;
            }

            if ($currentStatus === OperationRunStatus::CancelRequested) {
                $now = $this->now();
                $this->connection->executeStatement(
                    'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, updated_at = ?, completed_at = ? WHERE run_id = ? AND status = ?',
                    [OperationRunItemStatus::Cancelled->value, $now, $now, $runId, OperationRunItemStatus::Queued->value],
                );
                $this->refreshCounts($runId);
                $runningItems = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ? AND status = ?',
                    [$runId, OperationRunItemStatus::Running->value],
                );
                if ($runningItems > 0) {
                    return OperationRunStatus::CancelRequested;
                }

                $cancelledItems = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ? AND status = ?',
                    [$runId, OperationRunItemStatus::Cancelled->value],
                );
                if ($cancelledItems > 0) {
                    $this->connection->update(Installer::TABLE_OPERATION_RUN, [
                        'status' => OperationRunStatus::Cancelled->value,
                        'updated_at' => $now,
                        'completed_at' => $now,
                    ], ['id' => $runId]);

                    return OperationRunStatus::Cancelled;
                }
            }

            $this->refreshCounts($runId);
            $run = $this->connection->fetchAssociative(
                'SELECT total_count, processed_count, succeeded_count, skipped_count, blocked_count, failed_count FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
                [$runId],
            );
            if ($run === false) {
                throw new \RuntimeException('Operation run not found.');
            }

            $status = match (true) {
                (int) $run['processed_count'] < (int) $run['total_count'] => $currentStatus,
                (int) $run['blocked_count'] === (int) $run['total_count'] => OperationRunStatus::Blocked,
                (int) $run['failed_count'] > 0 || (int) $run['skipped_count'] > 0 || (int) $run['blocked_count'] > 0 => OperationRunStatus::Partial,
                default => OperationRunStatus::Completed,
            };
            if ($status->isTerminal()) {
                $now = $this->now();
                $this->connection->update(Installer::TABLE_OPERATION_RUN, [
                    'status' => $status->value,
                    'updated_at' => $now,
                    'completed_at' => $now,
                ], ['id' => $runId]);
            }

            return $status;
        });
    }

    public function fail(string $runId, string $error): void
    {
        $this->connection->transactional(function () use ($runId, $error): void {
            $this->lockRun($runId);
            $status = $this->connection->fetchOne(
                'SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
                [$runId],
            );
            if ($status === false || OperationRunStatus::from((string) $status)->isTerminal()) {
                return;
            }

            $now = $this->now();
            $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, error_message = COALESCE(error_message, ?), updated_at = ?, completed_at = ? WHERE run_id = ? AND status IN (?, ?)',
                [OperationRunItemStatus::Failed->value, $error, $now, $now, $runId, OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value],
            );
            $this->refreshCounts($runId);
            $this->connection->update(Installer::TABLE_OPERATION_RUN, [
                'status' => OperationRunStatus::Failed->value,
                'error_message' => $error,
                'updated_at' => $now,
                'completed_at' => $now,
            ], ['id' => $runId]);
        });
    }

    /** @return array<string, mixed>|null */
    public function get(string $runId, ActorContext $actor): ?array
    {
        [$where, $params] = $this->actorWhere($actor);
        $run = $this->connection->fetchAssociative(
            'SELECT * FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ? AND ' . $where,
            [$runId, ...$params],
        );
        if ($run === false) {
            return null;
        }

        $items = $this->connection->fetchAllAssociative(
            'SELECT item_key, target_type, target_id, fingerprint, payload, state_payload, status, attempts, result_payload, error_message, created_at, updated_at, completed_at FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ? ORDER BY id ASC',
            [$runId],
        );
        $run['request_payload'] = $this->decode((string) $run['request_payload']);
        foreach ($items as &$item) {
            $item['payload'] = $this->decode((string) $item['payload']);
            $item['state_payload'] = $this->decode((string) $item['state_payload']);
            $item['result_payload'] = $item['result_payload'] === null ? null : $this->decode((string) $item['result_payload']);
        }
        unset($item);
        $run['items'] = $items;

        return $run;
    }

    /** @return list<array<string, mixed>> */
    public function recent(ActorContext $actor, int $limit = 20): array
    {
        [$where, $params] = $this->actorWhere($actor);
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(Installer::TABLE_OPERATION_RUN)
            ->where($where)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(min(100, max(1, $limit)));
        foreach ($params as $index => $parameter) {
            $query->setParameter($index, $parameter);
        }

        $runs = $query->executeQuery()->fetchAllAssociative();
        foreach ($runs as &$run) {
            $run['request_payload'] = $this->decode((string) $run['request_payload']);
        }
        unset($run);

        return $runs;
    }

    public function retry(string $runId, ActorContext $actor): ?string
    {
        return $this->connection->transactional(function () use ($runId, $actor): ?string {
            if (!$this->tryLockRun($runId)) {
                return null;
            }
            $run = $this->get($runId, $actor);
            if ($run === null || !OperationRunStatus::from((string) $run['status'])->isTerminal()) {
                return null;
            }

            $retryable = array_values(array_filter(
                $run['items'],
                static fn (array $item): bool => in_array($item['status'], [OperationRunItemStatus::Blocked->value, OperationRunItemStatus::Failed->value, OperationRunItemStatus::Cancelled->value], true),
            ));
            if ($retryable === []) {
                return null;
            }

            return $this->create(
                (string) $run['kind'],
                OperationRunActor::fromRun($run),
                array_map(static fn (array $item): array => [
                    'key' => (string) $item['item_key'],
                    'type' => (string) $item['target_type'],
                    'id' => $item['target_id'] === null ? null : (int) $item['target_id'],
                    'fingerprint' => $item['fingerprint'] === null ? null : (string) $item['fingerprint'],
                    'payload' => is_array($item['payload']) ? $item['payload'] : [],
                    'state' => is_array($item['state_payload']) ? $item['state_payload'] : [],
                ], $retryable),
                is_array($run['request_payload']) ? $run['request_payload'] : [],
                $runId,
            );
        });
    }

    private function lockRun(string $runId): void
    {
        if (!$this->tryLockRun($runId)) {
            throw new \RuntimeException('Operation run not found.');
        }
    }

    private function tryLockRun(string $runId): bool
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(Installer::TABLE_OPERATION_RUN)
            ->where('id = :id')
            ->setParameter('id', $runId);
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->forUpdate();
        }

        return $query->executeQuery()->fetchOne() !== false;
    }

    private function refreshCounts(string $runId): void
    {
        $counts = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total_count, SUM(CASE WHEN status IN (?, ?, ?, ?, ?) THEN 1 ELSE 0 END) AS processed_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS succeeded_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS skipped_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS blocked_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed_count FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?',
            [
                OperationRunItemStatus::Completed->value,
                OperationRunItemStatus::Blocked->value,
                OperationRunItemStatus::Skipped->value,
                OperationRunItemStatus::Failed->value,
                OperationRunItemStatus::Cancelled->value,
                OperationRunItemStatus::Completed->value,
                OperationRunItemStatus::Skipped->value,
                OperationRunItemStatus::Blocked->value,
                OperationRunItemStatus::Failed->value,
                $runId,
            ],
        );
        if ($counts === false) {
            return;
        }

        $this->connection->update(Installer::TABLE_OPERATION_RUN, [
            'total_count' => (int) $counts['total_count'],
            'processed_count' => (int) $counts['processed_count'],
            'succeeded_count' => (int) $counts['succeeded_count'],
            'skipped_count' => (int) $counts['skipped_count'],
            'blocked_count' => (int) $counts['blocked_count'],
            'failed_count' => (int) $counts['failed_count'],
            'updated_at' => $this->now(),
        ], ['id' => $runId]);
    }

    /** @return array{string, list<int|string>} */
    private function actorWhere(ActorContext $actor): array
    {
        if ($actor->type === ActorType::System) {
            return ['1 = 1', []];
        }
        if ($actor->type !== ActorType::User || $actor->userId === null) {
            return ['1 = 0', []];
        }

        return ['actor_type = ? AND actor_user_id = ?', [ActorType::User->value, $actor->userId]];
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
