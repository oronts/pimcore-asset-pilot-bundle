<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;

/**
 * Reports the async configuration. A running messenger worker cannot be verified from a synchronous
 * request, so async-on is Ok-with-a-reminder, never a false failure; the operator confirms the
 * consumer out of band.
 */
class AsyncTransportHealthCheck implements HealthCheckInterface
{
    public function __construct(
        protected readonly bool $asyncEnabled,
        protected readonly int $batchSize,
    ) {}

    public function name(): string
    {
        return 'async_transport';
    }

    public function run(): HealthCheckResult
    {
        if (!$this->asyncEnabled) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Ok,
                'Synchronous mode: moves run in-process on save.',
                ['async_enabled' => false],
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Ok,
            'Async enabled: ensure a worker consumes the transport (bin/console messenger:consume).',
            ['async_enabled' => true, 'batch_size' => $this->batchSize],
        );
    }
}
