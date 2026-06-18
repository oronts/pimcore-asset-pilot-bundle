<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health;

use Oronts\AssetPilotBundle\Model\HealthCheckResult;

/**
 * A single production-readiness probe. Tag an implementation with `oronts_asset_pilot.health_check`
 * to add it to the `asset-pilot:health` command and the GET /health endpoint.
 */
interface HealthCheckInterface
{
    public function name(): string;

    public function run(): HealthCheckResult;
}
