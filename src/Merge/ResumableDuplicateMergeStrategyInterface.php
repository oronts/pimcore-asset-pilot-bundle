<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

interface ResumableDuplicateMergeStrategyInterface extends DuplicateMergeStrategyInterface
{
    public function recoverDisposition(int $copyId, RepointReport $report): ?CopyDisposition;
}
