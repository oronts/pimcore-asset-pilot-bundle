<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Log\LoggerInterface;

/**
 * Runs every tagged health check and rolls the results up into one overall status (the worst).
 */
class HealthChecker implements HealthCheckerInterface
{
    /** @var list<HealthCheckInterface> */
    private array $checks;

    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        iterable $checks,
        private readonly LoggerInterface $logger,
    ) {
        $this->checks = $checks instanceof \Traversable ? iterator_to_array($checks, false) : array_values($checks);
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            // A failing probe must not abort the whole run; surface it as a Critical result and log
            // the cause server-side (the public message stays generic so internals never leak).
            try {
                $results[] = $check->run();
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: health check "{name}" threw: {error}', [
                    'name' => $check->name(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $results[] = new HealthCheckResult($check->name(), HealthStatus::Critical, 'Health check could not run.');
            }
        }

        return $results;
    }

    /**
     * @param list<HealthCheckResult> $results
     */
    public function overall(array $results): HealthStatus
    {
        $worst = HealthStatus::Ok;
        foreach ($results as $result) {
            if ($result->status->severity() > $worst->severity()) {
                $worst = $result->status;
            }
        }

        return $worst;
    }
}
