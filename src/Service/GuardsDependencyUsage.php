<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Pimcore\Model\Asset;

/**
 * Maps a live-dependency verdict to the operator-facing skip reason (or null when the asset is safe to
 * act on), so the reviewed unused-asset and quarantine guards cannot drift on which verdicts block a
 * mutation. The using service must expose `$this->dependencyVerifier`.
 */
trait GuardsDependencyUsage
{
    private function dependencyVerdictReason(Asset $asset): ?string
    {
        return match ($this->dependencyVerifier->verdict($asset)) {
            DependencyUsageVerdict::Referenced => 'Asset is referenced by a live Pimcore element dependency',
            DependencyUsageVerdict::Unknown => 'Dependency projection is not ready or contains dirty sources',
            DependencyUsageVerdict::Safe => null,
        };
    }
}
