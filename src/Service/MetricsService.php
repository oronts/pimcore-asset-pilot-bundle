<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;

/**
 * Operational metrics derived from the audit log: operation counts per status, the failure rate,
 * and the duration aggregate over completed moves. Pure aggregation over the audit gateway (no new
 * scan), suitable for a monitoring poll.
 */
class MetricsService
{
    public function __construct(
        protected readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{
     *     operations: array<string, int>,
     *     total: int,
     *     failureRate: float,
     *     durationMs: array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null}
     * }
     */
    public function collect(): array
    {
        $operations = [];
        $total = 0;
        foreach ($this->auditLogger->getStats() as $key => $value) {
            // getStats() also carries a 'by_class' map; keep only the per-status integer counters.
            if (!is_int($value)) {
                continue;
            }
            $operations[$key] = $value;
            $total += $value;
        }

        $failed = $operations['failed'] ?? 0;

        return [
            'operations' => $operations,
            'total' => $total,
            'failureRate' => $total > 0 ? round($failed / $total, 4) : 0.0,
            'durationMs' => $this->auditLogger->getDurationStats(),
        ];
    }
}
