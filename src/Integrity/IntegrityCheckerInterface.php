<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Integrity;

use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

/**
 * Verifies whether an asset binary still renders. Tag an implementation with
 * `oronts_asset_pilot.integrity_checker`; the composite picks the highest-priority checker whose
 * supports() matches. check() inspects the live asset; checkBinary() inspects a candidate binary
 * (a historical version) WITHOUT touching the live asset, so a heal can test versions safely.
 */
interface IntegrityCheckerInterface
{
    /** Higher wins when several checkers support the same asset. */
    public function priority(): int;

    public function supports(Asset $asset): bool;

    public function check(Asset $asset): IntegrityResult;

    public function checkBinary(string $binary, string $extension): IntegrityResult;
}
