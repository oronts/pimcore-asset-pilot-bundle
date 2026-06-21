<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

use Oronts\AssetPilotBundle\Model\MoveOperation;

interface AuditLoggerInterface
{
    public function isEnabled(): bool;

    public function getRetentionDays(): int;

    public function log(MoveOperation $operation): void;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 20, array $filters = []): array;

    /** @return array<string, mixed> */
    public function getStats(): array;

    /**
     * Duration aggregate over completed operations, for metrics.
     *
     * @return array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null}
     */
    public function getDurationStats(): array;

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function getPaginated(int $page = 1, int $limit = 20, array $filters = [], ?string $sort = null, ?string $order = null): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string, mixed> */
    public function getStatsByRule(string $ruleName): array;

    /** @return array<int, array<string, mixed>> */
    public function getClassBreakdown(): array;

    /**
     * Distinct objects whose organization failed, newest-failure-heaviest first, for failure replay.
     *
     * @param array{since?: string, rule_name?: string, object_class?: string} $filters
     * @return array<int, array{object_id: int, object_class: string, failures: int}>
     */
    public function getDistinctFailedObjects(array $filters = [], int $limit = 100): array;

    /**
     * Keyset-paginated export cursor: yields rows newest-first in bounded pages (memory stays flat).
     *
     * @param array<string, mixed> $filters
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateForExport(array $filters = [], int $chunkSize = 1000): \Generator;

    public function cleanup(int $retentionDays): int;
}
