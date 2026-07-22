<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;

/**
 * Aggregates health probes for both the HTTP endpoint and CLI command.
 * Implementations return complete typed results and deterministically roll them up.
 */
interface HealthCheckerInterface
{
    /** @return list<HealthCheckResult> */
    public function run(): array;

    /** @param list<HealthCheckResult> $results */
    public function overall(array $results): HealthStatus;
}
