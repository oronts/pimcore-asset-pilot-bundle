<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\DependencyProjectionStatus;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Oronts\AssetPilotBundle\Service\DependencyProjectionFreshnessInterface;
use Pimcore\Config;

class DependencyTrackingHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly DependencyProjectionFreshnessInterface $freshness) {}

    public function name(): string
    {
        return 'dependency_tracking';
    }

    public function run(): HealthCheckResult
    {
        try {
            $enabled = (bool) ($this->systemConfiguration()['dependency']['enabled'] ?? false);
        } catch (\Throwable) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Warning,
                'Pimcore dependency tracking configuration could not be verified.',
            );
        }

        if (!$enabled) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                'Pimcore dependency tracking is disabled; destructive unused-asset decisions must remain blocked.',
                ['enabled' => false, 'freshness_verified' => false],
            );
        }

        try {
            $projection = $this->freshness->status();
        } catch (\Throwable) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                'Dependency projection freshness could not be read; destructive unused-asset decisions remain blocked.',
                ['enabled' => true, 'freshness_verified' => false],
            );
        }

        $details = $this->details($projection);
        if ($projection->isSafe()) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Ok,
                'Pimcore dependency tracking and the indexed dependency projection are current.',
                $details,
            );
        }

        if ($projection->state === DependencyProjectionState::Failed) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                'Dependency projection rebuild failed; destructive unused-asset decisions remain blocked.',
                $details,
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Warning,
            $projection->dirtySources > 0
                ? 'Dependency projection contains dirty sources; destructive unused-asset decisions remain blocked.'
                : 'Dependency projection is not ready; run or resume asset-pilot:rebuild-dependency-projection.',
            $details,
        );
    }

    /** @return array<string, mixed> */
    private function details(DependencyProjectionStatus $status): array
    {
        return [
            'enabled' => true,
            'freshness_verified' => $status->isSafe(),
            'projection_state' => $status->state->value,
            'generation' => $status->generation,
            'dirty_sources' => $status->dirtySources,
            'source_count' => $status->sourceCount,
            'edge_count' => $status->edgeCount,
            'cursor_type' => $status->cursorType,
            'cursor_id' => $status->cursorId,
            'started_at' => $status->startedAt?->format(DATE_ATOM),
            'completed_at' => $status->completedAt?->format(DATE_ATOM),
            'error' => $status->error,
        ];
    }

    /** @return array<string, mixed> */
    protected function systemConfiguration(): array
    {
        return Config::getSystemConfiguration() ?? [];
    }
}
