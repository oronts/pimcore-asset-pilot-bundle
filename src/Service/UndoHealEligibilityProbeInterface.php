<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\UndoHealResult;

interface UndoHealEligibilityProbeInterface
{
    /** Metadata-only read probe. The mutation path repeats authoritative checks under its lock. */
    public function assessUndoEligibility(int $assetId, int $fromVersionId): UndoHealResult;
}
