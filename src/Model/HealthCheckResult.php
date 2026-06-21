<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\HealthStatus;

readonly class HealthCheckResult
{
    /**
     * @param array<string, mixed> $details optional structured context for the UI/CLI
     */
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
        public array $details = [],
    ) {}
}
