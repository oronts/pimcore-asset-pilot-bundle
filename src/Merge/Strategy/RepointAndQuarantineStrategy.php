<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\ResumableDuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Service\QuarantineServiceInterface;

/**
 * The default, reversible policy: once a copy's references are fully repointed onto the canonical
 * asset, move the copy to quarantine (restorable) rather than deleting it. A copy whose references
 * could not all be repointed is left in place and reported, never quarantined.
 */
class RepointAndQuarantineStrategy implements ResumableDuplicateMergeStrategyInterface
{
    use QuarantinesCopy;

    public function __construct(
        protected readonly QuarantineServiceInterface $quarantine,
    ) {}

    public function name(): string
    {
        return 'quarantine';
    }

    public function repointsReferences(): bool
    {
        return true;
    }

    public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
    {
        $copyId = $context->copyId();
        if (!$report->fullyRepointed) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, $this->blockedReason($report));
        }

        return $this->quarantineCopy($copyId);
    }

    private function blockedReason(RepointReport $report): string
    {
        return sprintf('%d reference(s) could not be repointed: %s', count($report->blocked), implode('; ', $report->blocked));
    }
}
