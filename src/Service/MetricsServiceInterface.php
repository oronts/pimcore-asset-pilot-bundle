<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface MetricsServiceInterface
{
    /**
     * @return array{
     *     operations: array<string, int>,
     *     total: int,
     *     moveTotal: int,
     *     failureRate: float,
     *     durationMs: array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null}
     * }
     */
    public function collect(): array;
}
