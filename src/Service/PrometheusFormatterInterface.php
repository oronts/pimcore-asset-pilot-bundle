<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface PrometheusFormatterInterface
{
    /**
     * @param array{
     *     operations: array<string, int>,
     *     total: int,
     *     moveTotal: int,
     *     failureRate: float,
     *     durationMs: array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null}
     * } $metrics
     */
    public function format(array $metrics): string;
}
