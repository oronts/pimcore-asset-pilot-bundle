<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Model\UndoHealResult;
use Pimcore\Model\Asset;

interface VersionRollbackHealerInterface extends UndoHealEligibilityProbeInterface
{
    public function healById(int $assetId, bool $dryRun = false, ?string $expectedFingerprint = null): HealResult;

    public function previewById(int $assetId): HealResult;

    /** @param list<int> $assetIds @param array<string, string> $expectedFingerprints @return array<int, HealResult> */
    public function healPlannedBatch(array $assetIds, array $expectedFingerprints): array;

    public function heal(Asset $asset, bool $dryRun = false): HealResult;

    public function undo(int $assetId): bool;

    public function undoDetailed(int $assetId, bool $dryRun = false): UndoHealResult;
}
