<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

interface AuditQueryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 20, array $filters = []): array;

    /** @return array<string, mixed> */
    public function getStats(): array;

    /** @return array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null} */
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
     * @param array{since?: string, rule_name?: string, object_class?: string, object_ids?: list<int>} $filters
     * @return array<int, array{object_id: int, object_class: string, failures: int}>
     */
    public function getDistinctFailedObjects(array $filters = [], int $limit = 100): array;
}
