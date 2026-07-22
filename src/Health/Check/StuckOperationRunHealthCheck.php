<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;

/**
 * Surfaces operation runs stuck in the queue. Because a run is only ever failed once a worker claims it,
 * a legitimate broker backlog is never age-failed; the cost is that a genuinely lost broker message leaves
 * its run Queued indefinitely. This probe warns when a run has been Queued past the configured threshold so
 * an operator can cancel and retry it, without the maintenance task ever terminalizing a real backlog.
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
                sprintf('%d operation run(s) have been queued longer than %d seconds and may have lost their broker message; cancel and retry them or verify the consumers.', $stuck, $this->warningAfterSeconds),
                ['stuck_queued_runs' => $stuck, 'warning_after_seconds' => $this->warningAfterSeconds],
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Ok,
            'No operation runs are stuck in the queue beyond the backlog threshold.',
            ['stuck_queued_runs' => 0, 'warning_after_seconds' => $this->warningAfterSeconds],
        );
    }
}
