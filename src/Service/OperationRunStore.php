<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;

final class OperationRunStore implements OperationRunStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $leaseSeconds = 300,
    ) {}

    /**
     * @param list<array{key: string, type: string, id?: int|null, fingerprint?: string|null, payload?: array<string, mixed>, state?: array<string, mixed>}> $items
     * @param array<string, mixed>                                                                                                             $request
     */
    public function create(OperationRunKind $kind, ActorContext $actor, array $items, array $request = [], ?string $retryOf = null, OperationRunStatus $initialStatus = OperationRunStatus::Queued): string
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Operation runs require at least one item.');
        }

        $runId = bin2hex(random_bytes(16));
        $now = $this->now();
        $this->connection->transactional(function () use ($runId, $kind, $actor, $items, $request, $retryOf, $now, $initialStatus): void {
            $attempt = 1;
            if ($retryOf !== null) {
                if (!$this->tryLockRun($retryOf)) {
                    throw new \InvalidArgumentException('The retry parent operation run does not exist.');
                }
                $parentAttempt = $this->connection->fetchOne(
                    'SELECT attempt FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
                    [$retryOf],
                );
                if ($parentAttempt === false) {
                    throw new \InvalidArgumentException('The retry parent operation run does not exist.');
                }
                if ($this->hasRetryChild($retryOf)) {
                    throw new \LogicException('The operation run already has a retry.');
                }

                $attempt = (int) $parentAttempt + 1;
            }

            $this->connection->insert(Installer::TABLE_OPERATION_RUN, [
                'id' => $runId,
                'kind' => $kind->value,
                'actor_type' => $actor->type->value,
                'actor_user_id' => $actor->userId,
                'status' => $initialStatus->value,
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

    /**
     * Committed pending-dispatch runs the relay must still publish, oldest first, with the data needed to
     * rebuild their organize message. Only committed rows are visible, so a run created inside a
     * not-yet-committed source transaction is never returned (and a rolled-back one vanishes).
     *
     * @return list<array{id: string, actorType: string, actorUserId: int|null, trigger: string, targets: list<array{id: int, fingerprint: string|null}>}>
     */
    public function dueForDispatch(int $limit): array
    {
        $runs = $this->connection->createQueryBuilder()
            ->select('id', 'actor_type', 'actor_user_id', 'request_payload')
            ->from(Installer::TABLE_OPERATION_RUN)
            ->where('status = :status')
            ->orderBy('created_at', 'ASC')
            ->setParameter('status', OperationRunStatus::PendingDispatch->value)
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($runs as $run) {
            $items = $this->connection->fetchAllAssociative(
                'SELECT target_id, fingerprint FROM ' . Installer::TABLE_OPERATION_RUN_ITEM
                . ' WHERE run_id = ? AND target_type = ? AND target_id IS NOT NULL ORDER BY target_id ASC',
                [$run['id'], 'data_object'],
            );
            $request = $this->decode((string) $run['request_payload']);
            $result[] = [
                'id' => (string) $run['id'],
                'actorType' => (string) $run['actor_type'],
                'actorUserId' => $run['actor_user_id'] !== null ? (int) $run['actor_user_id'] : null,
                'trigger' => (string) ($request['trigger'] ?? ''),
                'targets' => array_map(static fn (array $item): array => [
                    'id' => (int) $item['target_id'],
                    'fingerprint' => $item['fingerprint'] !== null ? (string) $item['fingerprint'] : null,
                ], $items),
            ];
        }

        return $result;
    }

    /**
     * Atomically transition a committed pending-dispatch run to Queued once its message is published.
     * Returns true only for the caller that owned the transition, so a concurrent/duplicate relay never
     * re-queues an already-dispatched run. Publish happens before this call, so a crash in between leaves
     * the run pending and it is re-published (idempotent at the worker, which claims the item under a token).
     */
    public function markDispatched(string $runId): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
            [OperationRunStatus::Queued->value, $this->now(), $runId, OperationRunStatus::PendingDispatch->value],
        ) === 1;
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

        // Accept PendingDispatch: the relay publishes just before markDispatched, so a fast worker in that window
        // must be able to resume the still-pending run (markDispatched then finds it Running and no-ops).
        $updated = $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET status = ?, started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ? AND status IN (?, ?, ?)',
            [OperationRunStatus::Running->value, $now, $now, $runId, OperationRunStatus::PendingDispatch->value, OperationRunStatus::Queued->value, OperationRunStatus::Running->value],
        );
        if ($updated === 1) {
            return true;
        }

        return $this->connection->fetchOne(
            'SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
            [$runId],
        ) === OperationRunStatus::Running->value;
    }

    public function startItem(string $runId, string $itemKey, ?string $token = null): bool
    {
        $now = $this->now();

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, attempts = attempts + 1, claim_token = ?, lease_expires_at = ?, updated_at = ? WHERE run_id = ? AND item_key = ? AND status = ? AND EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ? AND status = ?)',
            [OperationRunItemStatus::Running->value, $token, $this->leaseExpiry($token, $now), $now, $runId, $itemKey, OperationRunItemStatus::Queued->value, $runId, OperationRunStatus::Running->value],
        ) === 1;
    }

    public function resumeItem(string $runId, string $itemKey, ?string $token = null): bool
    {
        $now = $this->now();

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, attempts = attempts + 1, claim_token = ?, lease_expires_at = ?, updated_at = ? WHERE run_id = ? AND item_key = ? AND status IN (?, ?) AND EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ? AND status = ?)',
            [OperationRunItemStatus::Running->value, $token, $this->leaseExpiry($token, $now), $now, $runId, $itemKey, OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value, $runId, OperationRunStatus::Running->value],
        ) === 1;
    }

    /**
     * Renew a running item's liveness lease. Fenced by the caller's claim token and a still-live lease so a
     * zombie worker (one whose item was reclaimed or already reconciled) cannot resurrect it. Returns false
     * when ownership was lost, so the caller must abort before its next write.
     */
    public function renewItemLease(string $runId, string $itemKey, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $now = $this->now();
        $updated = $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET lease_expires_at = ?, updated_at = ? WHERE run_id = ? AND item_key = ? AND status = ? AND claim_token = ? AND lease_expires_at > ?',
            [$this->leaseExpiry($token, $now), $now, $runId, $itemKey, OperationRunItemStatus::Running->value, $token, $now],
        );
        if ($updated === 1) {
            return true;
        }

        // MariaDB reports zero changed rows when the renewed lease equals the stored value (same-second
        // renewal), so confirm current ownership directly before treating the lease as lost.
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ? AND item_key = ? AND status = ? AND claim_token = ? AND lease_expires_at > ?',
            [$runId, $itemKey, OperationRunItemStatus::Running->value, $token, $this->now()],
        ) === 1;
    }

    /** @param array<string, mixed> $state */
    public function updateItemState(string $runId, string $itemKey, array $state, ?string $token = null): bool
    {
        $sql = 'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET state_payload = ?, updated_at = ? WHERE run_id = ? AND item_key = ? AND status IN (?, ?)';
        $params = [$this->encode($state), $this->now(), $runId, $itemKey, OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value];
        if ($token !== null) {
            $sql .= ' AND claim_token = ?';
            $params[] = $token;
        }

        return $this->connection->executeStatement($sql, $params) === 1;
    }

    /** @param array<string, mixed> $result */
    public function completeItem(
        string $runId,
        string $itemKey,
        OperationRunItemStatus $status,
        array $result = [],
        ?string $error = null,
        ?string $token = null,
    ): bool {
        if (!$status->isTerminal()) {
            throw new \InvalidArgumentException('An operation run item can only complete with a terminal status.');
        }
        return $this->connection->transactional(function () use ($runId, $itemKey, $status, $result, $error, $token): bool {
            $this->lockRun($runId);
            $now = $this->now();
            $sql = 'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, result_payload = ?, error_message = ?, updated_at = ?, completed_at = ?, claim_token = NULL, lease_expires_at = NULL WHERE run_id = ? AND item_key = ? AND status IN (?, ?)';
            $params = [$status->value, $this->encode($result), $error, $now, $now, $runId, $itemKey, OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value];
            if ($token !== null) {
                $sql .= ' AND claim_token = ?';
                $params[] = $token;
            }
            $updated = $this->connection->executeStatement($sql, $params);
            if ($updated === 1) {
                $this->refreshCounts($runId);
            }

            return $updated === 1;
        });
    }

    public function requestCancellation(string $runId, ActorContext $actor): bool
    {
        [$where, $params] = $this->actorWhere($actor);

        // A pending run has no worker to finalize a CancelRequested, so cancel it terminally; queued/running go
        // to CancelRequested for the worker. A cancelled pending run is no longer pending_dispatch, so never relayed.
        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN
            . ' SET status = CASE WHEN status = ? THEN ? ELSE ? END, updated_at = ?'
            . ' WHERE id = ? AND status IN (?, ?, ?) AND ' . $where,
            [
                OperationRunStatus::PendingDispatch->value, OperationRunStatus::Cancelled->value, OperationRunStatus::CancelRequested->value,
                $this->now(), $runId,
                OperationRunStatus::PendingDispatch->value, OperationRunStatus::Queued->value, OperationRunStatus::Running->value,
                ...$params,
            ],
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

    public function reconcileExpiredItemLeases(int $batch): int
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('Expired item-lease reconciliation requires a positive batch size.');
        }

        $now = $this->now();
        $candidates = $this->connection->createQueryBuilder()
            ->select('id', 'run_id', 'claim_token')
            ->from(Installer::TABLE_OPERATION_RUN_ITEM)
            ->where('status = :running')
            ->andWhere('lease_expires_at IS NOT NULL')
            ->andWhere('lease_expires_at < :now')
            ->setParameter('running', OperationRunItemStatus::Running->value)
            ->setParameter('now', $now)
            ->orderBy('lease_expires_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($batch)
            ->executeQuery()
            ->fetchAllAssociative();

        $error = 'The operation run item lease expired without a worker heartbeat and was reconciled as failed.';
        $reconciled = 0;
        $affectedRuns = [];
        foreach ($candidates as $candidate) {
            $stamp = $this->now();
            $failed = $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET status = ?, claim_token = NULL, lease_expires_at = NULL, error_message = COALESCE(error_message, ?), updated_at = ?, completed_at = ? WHERE id = ? AND status = ? AND claim_token = ? AND lease_expires_at < ?',
                [OperationRunItemStatus::Failed->value, $error, $stamp, $stamp, (int) $candidate['id'], OperationRunItemStatus::Running->value, $candidate['claim_token'], $stamp],
            );
            if ($failed === 1) {
                ++$reconciled;
                $affectedRuns[(string) $candidate['run_id']] = true;
            }
        }

        $this->finalizeAffectedRuns($affectedRuns);

        return $reconciled;
    }

    public function reconcileUnfinalizedRuns(int $batch): int
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('Unfinalized-run reconciliation requires a positive batch size.');
        }

        $nonTerminal = array_map(
            static fn (OperationRunStatus $status): string => $status->value,
            array_filter(OperationRunStatus::cases(), static fn (OperationRunStatus $status): bool => !$status->isTerminal()),
        );

        $candidates = $this->connection->createQueryBuilder()
            ->select('r.id')
            ->from(Installer::TABLE_OPERATION_RUN, 'r')
            ->where('r.status IN (:nonTerminal)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' i WHERE i.run_id = r.id AND i.status IN (:open))')
            ->setParameter('nonTerminal', $nonTerminal, ArrayParameterType::STRING)
            ->setParameter('open', [OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value], ArrayParameterType::STRING)
            ->orderBy('r.updated_at', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($batch)
            ->executeQuery()
            ->fetchFirstColumn();

        $reconciled = 0;
        foreach ($candidates as $runId) {
            try {
                if ($this->finish((string) $runId)->isTerminal()) {
                    ++$reconciled;
                }
            } catch (\Throwable) {
                // A run that still cannot finalize (transient lock contention) is retried next maintenance pass.
            }
        }

        return $reconciled;
    }

    public function reconcileAbandonedRunningRuns(int $batch, int $staleSeconds): int
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('Abandoned-run reconciliation requires a positive batch size.');
        }
        if ($staleSeconds < 1) {
            throw new \InvalidArgumentException('Abandoned-run reconciliation requires a positive stale interval.');
        }

        $reference = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->now(), new \DateTimeZone('UTC'));
        if ($reference === false) {
            throw new \RuntimeException('Unable to derive the abandoned-run cutoff.');
        }
        $cutoff = $reference->modify(sprintf('-%d seconds', $staleSeconds))->format('Y-m-d H:i:s');

        // A Running run stale past the (long) backlog window that still holds a queued item has no live worker:
        // its expired lease was already reaped by reconcileExpiredItemLeases, and a synchronous run (duplicate
        // merge, sync bulk organize) has no message to redeliver. Fail it so it stops being an invisible,
        // unprunable strand. Queued/pending_dispatch runs are excluded: that backlog is still the worker/relay's.
        $candidates = $this->connection->createQueryBuilder()
            ->select('r.id')
            ->from(Installer::TABLE_OPERATION_RUN, 'r')
            ->where('r.status = :running')
            ->andWhere('r.updated_at < :cutoff')
            ->andWhere('EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' i WHERE i.run_id = r.id AND i.status = :queued)')
            ->setParameter('running', OperationRunStatus::Running->value)
            ->setParameter('cutoff', $cutoff)
            ->setParameter('queued', OperationRunItemStatus::Queued->value)
            ->orderBy('r.updated_at', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($batch)
            ->executeQuery()
            ->fetchFirstColumn();

        $error = 'The operation run stopped progressing past the backlog threshold with no live worker and was reconciled as failed.';
        $reconciled = 0;
        foreach ($candidates as $runId) {
            $this->fail((string) $runId, $error);
            ++$reconciled;
        }

        return $reconciled;
    }

    /** @param array<string, true> $affectedRuns */
    private function finalizeAffectedRuns(array $affectedRuns): void
    {
        foreach (array_keys($affectedRuns) as $runId) {
            try {
                $this->finish($runId);
            } catch (\Throwable) {
                // A transient finish() failure or an interrupted process must not strand the sibling runs in
                // this batch; reconcileUnfinalizedRuns re-drives any run left non-terminal with terminal items.
            }
        }
    }

    public function countRunsQueuedLongerThan(int $seconds): int
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Queued-run backlog counting requires a positive interval.');
        }

        $reference = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->now(), new \DateTimeZone('UTC'));
        if ($reference === false) {
            throw new \RuntimeException('Unable to derive the queued-run backlog cutoff.');
        }
        $cutoff = $reference->modify(sprintf('-%d seconds', $seconds))->format('Y-m-d H:i:s');

        // Count queued (lost message / stalled worker) AND pending_dispatch (relay not scheduled) so neither stranded state is invisible.
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE status IN (?, ?) AND created_at < ?',
            [OperationRunStatus::Queued->value, OperationRunStatus::PendingDispatch->value, $cutoff],
        );
    }

    protected function leaseExpiry(?string $token, string $now): ?string
    {
        if ($token === null) {
            return null;
        }

        $reference = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now, new \DateTimeZone('UTC'));
        if ($reference === false) {
            throw new \RuntimeException('Unable to derive the operation run item lease expiry.');
        }

        return $reference->modify(sprintf('+%d seconds', max(1, $this->leaseSeconds)))->format('Y-m-d H:i:s');
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

            if ($this->hasRetryChild($runId)) {
                return null;
            }
            $kind = is_string($run['kind'] ?? null)
                ? OperationRunKind::tryFrom($run['kind'])
                : null;
            if ($kind === null) {
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
                $kind,
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
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET updated_at = updated_at WHERE id = ?',
                [$runId],
            ) === 1;
        }

        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(Installer::TABLE_OPERATION_RUN)
            ->where('id = :id')
            ->setParameter('id', $runId);
        $query->forUpdate();

        return $query->executeQuery()->fetchOne() !== false;
    }

    private function hasRetryChild(string $runId): bool
    {
        return $this->connection->fetchOne(
            'SELECT id FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE retry_of = ?',
            [$runId],
        ) !== false;
    }

    public function rootId(string $runId): string
    {
        $current = $runId;
        for ($guard = 0; $guard < 1000; ++$guard) {
            $parent = $this->connection->fetchOne(
                'SELECT retry_of FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?',
                [$current],
            );
            if (!is_string($parent) || $parent === '') {
                return $current;
            }
            $current = $parent;
        }

        return $current;
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
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
