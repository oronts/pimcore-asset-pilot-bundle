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

        $result = $this->quarantine->quarantine([$copyId]);
        if (($result['quarantined'] ?? 0) > 0) {
            return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
        }

        return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'quarantine did not move the copy');
    }

    public function recoverDisposition(int $copyId, RepointReport $report): ?CopyDisposition
    {
        return $this->quarantine->recoverQuarantine($copyId)
            ? new CopyDisposition($copyId, DispositionOutcome::Quarantined)
            : null;
    }

    private function blockedReason(RepointReport $report): string
    {
        return sprintf('%d reference(s) could not be repointed: %s', count($report->blocked), implode('; ', $report->blocked));
    }
}
