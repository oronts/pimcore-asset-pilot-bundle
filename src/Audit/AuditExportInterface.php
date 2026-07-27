<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

interface AuditExportInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateForExport(array $filters = [], int $chunkSize = 1000): \Generator;
}
