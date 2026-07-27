<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\ResumableDuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolverInterface;
use Oronts\AssetPilotBundle\Service\QuarantineServiceInterface;

/**
 * The lowest-risk policy: never repoints references. It quarantines only a copy that is already
 * unreferenced and leaves (reports) any copy still referenced, so consolidating referenced duplicates
 * is a deliberate choice of one of the repointing strategies, not this one.
 */
class IsolateUnreferencedStrategy implements ResumableDuplicateMergeStrategyInterface
{
    public function __construct(
        protected readonly AssetDependencyResolverInterface $dependencies,
        protected readonly QuarantineServiceInterface $quarantine,
    ) {}

    public function name(): string
    {
        return 'isolate';
    }

    public function repointsReferences(): bool
    {
        return false;
    }

    public function recoverDisposition(int $copyId, RepointReport $report): ?CopyDisposition
    {
        return $this->quarantine->recoverQuarantine($copyId)
            ? new CopyDisposition($copyId, DispositionOutcome::Quarantined)
            : null;
    }

    public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
    {
        $copyId = $context->copyId();
        if ($this->dependencies->dependentObjectIds($copyId, 1) !== []) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, 'still referenced by at least one object');
        }

        $result = $this->quarantine->quarantine([$copyId]);
        if (($result['quarantined'] ?? 0) > 0) {
            return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
        }

        return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'quarantine did not move the copy');
    }
}
