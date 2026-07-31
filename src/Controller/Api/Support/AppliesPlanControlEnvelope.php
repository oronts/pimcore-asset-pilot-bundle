<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

/**
 * Wraps a bulk-mutation result in the shared apply-response envelope (dryRun, planToken, and eligible
 * count), so every reviewed apply endpoint returns one consistent shape.
 */
trait AppliesPlanControlEnvelope
{
    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function withPlanControl(array $result, bool $dryRun, ?string $planToken, int $requested): array
    {
        $result['observerWarnings'] ??= [];

        return [
            ...$result,
            'dryRun' => $dryRun,
            'planToken' => $planToken,
            'eligible' => max(0, $requested - (int) ($result['failed'] ?? 0)),
        ];
    }
}
