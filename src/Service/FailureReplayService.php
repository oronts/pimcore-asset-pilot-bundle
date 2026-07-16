<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;

class FailureReplayService
{
    public function __construct(
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly int $defaultLimit = 100,
    ) {}

    /**
     * @param array{since?: string, rule_name?: string, object_class?: string, object_ids?: list<int>} $filters
     *
     * @return list<int>
     */
    public function selectObjects(array $filters = [], ?int $limit = null): array
    {
        $candidates = $this->auditLogger->getDistinctFailedObjects($filters, $limit ?? $this->defaultLimit);

        return array_values(array_unique(array_filter(
            array_map(static fn (array $row): int => (int) ($row['object_id'] ?? 0), $candidates),
            static fn (int $id): bool => $id > 0,
        )));
    }

}
