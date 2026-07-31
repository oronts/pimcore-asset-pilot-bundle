<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\RepointReport;

/**
 * Shared quarantine disposition for the merge strategies that move a duplicate copy to quarantine
 * (restorable): the recover-side mapping and the quarantine-and-map helper, so the two policies cannot
 * drift on the quarantine result contract. The using strategy must expose `$this->quarantine`.
 */
trait QuarantinesCopy
{
    public function recoverDisposition(int $copyId, RepointReport $report): ?CopyDisposition
    {
        return $this->quarantine->recoverQuarantine($copyId)
            ? new CopyDisposition($copyId, DispositionOutcome::Quarantined)
            : null;
    }

    protected function quarantineCopy(int $copyId): CopyDisposition
    {
        $result = $this->quarantine->quarantine([$copyId]);
        if (($result['quarantined'] ?? 0) > 0) {
            return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
        }

        return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'quarantine did not move the copy');
    }
}
