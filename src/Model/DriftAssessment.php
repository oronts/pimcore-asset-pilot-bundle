<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\DriftEligibility;

readonly class DriftAssessment
{
    public function __construct(
        public string $targetPath,
        public DriftEligibility $eligibility,
        public ?string $reason = null,
    ) {}
}
