<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;

interface OperationRunStoreInterface
{
    /**
     * @param list<array{key: string, type: string, id?: int|null, fingerprint?: string|null, payload?: array<string, mixed>, state?: array<string, mixed>}> $items
     * @param array<string, mixed>                                                                                                             $request
     */
    public function create(OperationRunKind $kind, ActorContext $actor, array $items, array $request = [], ?string $retryOf = null, OperationRunStatus $initialStatus = OperationRunStatus::Queued, ?string $runId = null): string;

    /**
     * @return list<array{id: string, actorType: string, actorUserId: int|null, trigger: string, targets: list<array{id: int, fingerprint: string|null}>}>
     */
    public function dueForDispatch(int $limit): array;

    public function markDispatched(string $runId): bool;

    public function start(string $runId): bool;

    public function resume(string $runId): bool;

    public function startItem(string $runId, string $itemKey, ?string $token = null): bool;

    public function resumeItem(string $runId, string $itemKey, ?string $token = null): bool;

    /**
     * Renew a running item's liveness lease, fenced by the caller's claim token. Returns false when
     * ownership was lost (the item was reclaimed or already reconciled), so the caller must abort.
     */
    public function renewItemLease(string $runId, string $itemKey, string $token): bool;

    /** @param array<string, mixed> $state */
    public function updateItemState(string $runId, string $itemKey, array $state, ?string $token = null): bool;

    /** @param array<string, mixed> $result */
    public function completeItem(
        string $runId,
        string $itemKey,
        OperationRunItemStatus $status,
        array $result = [],
        ?string $error = null,
        ?string $token = null,
    ): bool;

    public function requestCancellation(string $runId, ActorContext $actor): bool;

    /**
     * The data-object target ids of a run, so a caller with LoopGuard can clear each object's dispatch marker.
     *
     * @return list<int>
     */
    public function dataObjectTargets(string $runId): array;

    /** @phpstan-impure */
    public function isCancellationRequested(string $runId): bool;

    public function finish(string $runId): OperationRunStatus;

    public function fail(string $runId, string $error): void;

    /**
     * Fail running items whose durable liveness lease expired (an abandoned worker), then finish their
     * runs from the resulting item outcomes. Queued backlog and heartbeated long work have no expired
     * lease and are left untouched. Bounds to $batch items per call. Returns the number reconciled.
     */
    public function reconcileExpiredItemLeases(int $batch): int;

    /**
     * Backstop for durability: finalize any run left in a non-terminal status whose items are all terminal
     * (its finish() was interrupted by a transient failure or a process restart between the last item
     * completion and finalization). Idempotent and re-drivable; excludes runs with any queued/running item.
     * Returns the number finalized.
     */
    public function reconcileUnfinalizedRuns(int $batch): int;

    /**
     * Count runs still Queued longer than $seconds. A run is only reconciled when a worker claims it, so a
     * long-queued run whose broker message was genuinely lost is deliberately never age-failed (that would
     * kill a legitimate backlog); this count surfaces it for an operator to cancel/retry via the health check.
     */
    public function countRunsQueuedLongerThan(int $seconds): int;

    /**
     * Fail runs a worker START()ed to Running that have not progressed past $staleSeconds while still holding a
     * queued item: a synchronous run (duplicate merge, sync bulk organize) whose process crashed has no message
     * to redeliver, so nothing else drains it. Excludes queued/pending backlog. Bounds to $batch. Returns count.
     */
    public function reconcileAbandonedRunningRuns(int $batch, int $staleSeconds): int;

    /** @return array<string, mixed>|null */
    public function get(string $runId, ActorContext $actor): ?array;

    /** @return list<array<string, mixed>> */
    public function recent(ActorContext $actor, int $limit = 20): array;

    public function retry(string $runId, ActorContext $actor): ?string;

    /**
     * The root run id at the top of this run's `retry_of` chain (itself when it is not a retry child). Stable
     * across attempt, resume, retry, and retry-of-retry, so it anchors an external idempotency key that must
     * not change between attempts of one reviewed operation.
     */
    public function rootId(string $runId): string;
}
