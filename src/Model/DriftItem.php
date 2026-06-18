<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * One asset that is not where the current rules say it should be: it sits at currentPath but a rule
 * resolves it to expectedPath. Surfaced by the location-drift audit after a rule change relocates
 * where previously-organized assets ought to live.
 */
readonly class DriftItem
{
    public function __construct(
        public int $assetId,
        public string $currentPath,
        public string $expectedPath,
        public string $ruleName,
    ) {}
}
