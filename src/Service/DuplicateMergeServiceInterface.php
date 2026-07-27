<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;

interface DuplicateMergeServiceInterface
{
    /** @return list<string> */
    public function availableStrategies(): array;

    public function defaultStrategyName(): string;

    public function preview(DuplicateGroup $group, ?int $canonicalId = null, ?string $strategyName = null): MergeOutcome;

    /** @param array<string, string> $expectedFingerprints */
    public function merge(DuplicateGroup $group, array $expectedFingerprints, ?int $canonicalId = null, ?string $strategyName = null): MergeOutcome;

    public function resume(string $runId): MergeOutcome;

    /** @return list<ApplyPlanTarget> */
    public function planTargets(DuplicateGroup $group, int $canonicalId): array;

    /** @return array<string, string> */
    public function fingerprintMap(DuplicateGroup $group, int $canonicalId): array;
}
