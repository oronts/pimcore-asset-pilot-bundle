<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Merge\RepointPreflight;
use Oronts\AssetPilotBundle\Merge\RepointReport;

interface DuplicateReferenceRepointerInterface
{
    public function preflight(int $fromAssetId, int $toAssetId, string $permission): RepointPreflight;

    public function repoint(int $fromAssetId, int $toAssetId, bool $dryRun = false): RepointReport;
}
