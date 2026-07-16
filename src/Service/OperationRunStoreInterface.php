<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;

interface OperationRunStoreInterface
{
    /**
     * @param list<array{key: string, type: string, id?: int|null, fingerprint?: string|null, payload?: array<string, mixed>, state?: array<string, mixed>}> $items
     * @param array<string, mixed>                                                                                                             $request
     */
    public function create(string $kind, ActorContext $actor, array $items, array $request = [], ?string $retryOf = null): string;

    public function start(string $runId): bool;

    public function resume(string $runId): bool;

    public function startItem(string $runId, string $itemKey): bool;

    public function resumeItem(string $runId, string $itemKey): bool;

    /** @param array<string, mixed> $state */
    public function updateItemState(string $runId, string $itemKey, array $state): bool;

    /** @param array<string, mixed> $result */
    public function completeItem(
        string $runId,
        string $itemKey,
        OperationRunItemStatus $status,
        array $result = [],
        ?string $error = null,
    ): bool;

    public function requestCancellation(string $runId, ActorContext $actor): bool;

    /** @phpstan-impure */
    public function isCancellationRequested(string $runId): bool;

    public function finish(string $runId): OperationRunStatus;

    public function fail(string $runId, string $error): void;

    /** @return array<string, mixed>|null */
    public function get(string $runId, ActorContext $actor): ?array;

    /** @return list<array<string, mixed>> */
    public function recent(ActorContext $actor, int $limit = 20): array;

    public function retry(string $runId, ActorContext $actor): ?string;
}
