<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

/**
 * Renders the MetricsService aggregate as Prometheus text-exposition format. Kept separate from any
 * HTTP route because every REST endpoint here is permission-gated (a Prometheus scraper carries no
 * Studio session); the idiomatic path is `asset-pilot:metrics --format=prometheus` written to a
 * node_exporter textfile collector or pushed to a pushgateway.
 */
class PrometheusFormatter
{
    private const string PREFIX = 'asset_pilot';

    /**
     * @param array{
     *     operations: array<string, int>,
     *     total: int,
     *     failureRate: float,
     *     durationMs: array{count: int, avgMs: float|null, minMs: int|null, maxMs: int|null}
     * } $metrics
     */
    public function format(array $metrics): string
    {
        $lines = [];

        // A gauge, not a counter: the values are a current audit-log snapshot and can decrease when
        // old rows are pruned by retention, so they are not monotonic.
        $opsName = self::PREFIX . '_operations';
        $lines[] = sprintf('# HELP %s Asset Pilot operations by status (current audit-log snapshot; can decrease as retention prunes rows).', $opsName);
        $lines[] = sprintf('# TYPE %s gauge', $opsName);
        foreach ($metrics['operations'] as $status => $count) {
            $lines[] = sprintf('%s{status="%s"} %d', $opsName, $this->escapeLabel((string) $status), $count);
        }

        $this->gauge($lines, '_operations_count', 'Total Asset Pilot operations.', $metrics['total']);
        $this->gauge($lines, '_failure_rate', 'Failed-operation ratio (0..1).', $metrics['failureRate']);

        $duration = $metrics['durationMs'];
        $durationName = self::PREFIX . '_operation_duration_ms';
        $aggregates = array_filter(
            ['avg' => $duration['avgMs'], 'min' => $duration['minMs'], 'max' => $duration['maxMs']],
            static fn ($value): bool => $value !== null,
        );
        if ($aggregates !== []) {
            $lines[] = sprintf('# HELP %s Completed-move duration aggregate (ms).', $durationName);
            $lines[] = sprintf('# TYPE %s gauge', $durationName);
            foreach ($aggregates as $aggregate => $value) {
                $lines[] = sprintf('%s{aggregate="%s"} %s', $durationName, $aggregate, $this->number($value));
            }
        }
        $this->gauge($lines, '_operation_duration_count', 'Completed moves with a recorded duration.', $duration['count']);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     */
    private function gauge(array &$lines, string $suffix, string $help, int|float $value): void
    {
        $name = self::PREFIX . $suffix;
        $lines[] = sprintf('# HELP %s %s', $name, $help);
        $lines[] = sprintf('# TYPE %s gauge', $name);
        $lines[] = sprintf('%s %s', $name, $this->number($value));
    }

    private function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
