<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;

/**
 * Operational metrics derived from the audit log: operation counts per status, the failure rate,
 * and the duration aggregate over completed moves. Pure aggregation over the audit gateway (no new
 * scan), suitable for a monitoring poll.
 */
class MetricsService implements MetricsServiceInterface
{
    public function __construct(
        protected readonly AuditQueryInterface $auditLogger,
    ) {}

    /**
     * @return array{
     *     operations: array<string, int>,
     *     total: int,
     *     moveTotal: int,
     *     failureRate: float,
     *     durationMs: array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null}
     * }
     */
    public function collect(): array
    {
        $operations = [];
        $total = 0;
        foreach ($this->auditLogger->getStats() as $key => $value) {
            if (!is_int($value) || OperationStatus::tryFrom((string) $key) === null) {
                continue;
            }
            $operations[$key] = $value;
            $total += $value;
        }

        $failed = $operations[OperationStatus::Failed->value] ?? 0;
        $moveTotal = $total
            - ($operations[OperationStatus::Pending->value] ?? 0)
            - ($operations[OperationStatus::InProgress->value] ?? 0)
            - ($operations[OperationStatus::RecoveryRequired->value] ?? 0);

        return [
            'operations' => $operations,
            'total' => $total,
            'moveTotal' => $moveTotal,
            'failureRate' => $moveTotal > 0 ? round($failed / $moveTotal, 4) : 0.0,
            'durationMs' => $this->auditLogger->getDurationStats(),
        ];
    }
}
