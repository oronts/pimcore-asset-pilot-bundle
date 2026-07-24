<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class QueuedOrganizationRun
{
    public function __construct(
        public string $runId,
        public int $objectCount,
        public int $batchCount,
    ) {}
}
