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
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function getDistinctAssetsByRule(string $ruleName, int $page = 1, int $limit = 50, array $filters = []): array;

    public function cleanup(int $retentionDays): int;
}
