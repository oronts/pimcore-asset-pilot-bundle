<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Oronts\AssetPilotBundle\Service\QuarantineService;

/**
 * The lowest-risk policy: never repoints references. It quarantines only a copy that is already
 * unreferenced and leaves (reports) any copy still referenced, so consolidating referenced duplicates
 * is a deliberate choice of one of the repointing strategies, not this one.
 */
class IsolateUnreferencedStrategy implements DuplicateMergeStrategyInterface
{
    public function __construct(
        protected readonly AssetDependencyResolver $dependencies,
        protected readonly QuarantineService $quarantine,
    ) {}

    public function name(): string
    {
        return 'isolate';
    }

    public function repointsReferences(): bool
    {
        return false;
    }

    public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition
    {
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
