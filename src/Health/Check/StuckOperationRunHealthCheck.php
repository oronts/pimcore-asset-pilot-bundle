<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;

/**
 * Warns when a run has waited past the threshold before a worker processes it: queued (lost message / stalled
 * consumer) or pending_dispatch (the pimcore:maintenance relay is not scheduled). A run is only failed once a
 * worker claims it, so a legitimate backlog is never age-failed.
 */
class StuckOperationRunHealthCheck implements HealthCheckInterface
{
    public function __construct(
        protected readonly OperationRunStoreInterface $runs,
        protected readonly int $warningAfterSeconds = 86400,
    ) {}

    public function name(): string
    {
        return 'operation_run_backlog';
    }

    public function run(): HealthCheckResult
    {
        try {
            $stuck = $this->runs->countRunsQueuedLongerThan($this->warningAfterSeconds);
        } catch (\Throwable $e) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Warning,
                'The operation-run backlog could not be inspected.',
                ['error' => $e->getMessage(), 'warning_after_seconds' => $this->warningAfterSeconds],
            );
        }

        if ($stuck > 0) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Warning,
                sprintf('%d operation run(s) have been awaiting dispatch or queued longer than %d seconds; verify the pimcore:maintenance scheduler (publishes pending runs) and the asset_pilot consumer, then cancel and retry any stranded run.', $stuck, $this->warningAfterSeconds),
                ['stuck_backlog_runs' => $stuck, 'warning_after_seconds' => $this->warningAfterSeconds],
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Ok,
            'No operation runs are stuck awaiting dispatch or in the queue beyond the backlog threshold.',
            ['stuck_backlog_runs' => 0, 'warning_after_seconds' => $this->warningAfterSeconds],
        );
    }
}
