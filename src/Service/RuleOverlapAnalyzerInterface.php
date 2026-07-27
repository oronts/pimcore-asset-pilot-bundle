<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\RuleOverlap;

interface RuleOverlapAnalyzerInterface
{
    /** @return list<RuleOverlap> */
    public function analyze(): array;
}
