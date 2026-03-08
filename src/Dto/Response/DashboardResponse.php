<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Dto\Response;

readonly class DashboardResponse
{
    public function __construct(
        public int $totalOrganized,
        public int $totalPending,
        public int $totalFailed,
        public int $totalSkipped,
        public int $rulesCount,
        public array $recentOperations,
        public array $operationsByClass,
    ) {}
}
