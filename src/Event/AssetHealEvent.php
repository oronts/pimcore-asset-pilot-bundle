<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Pimcore\Model\Asset;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired around a version-rollback heal. INTEGRITY_PRE_HEAL is cancellable (a listener may veto the
 * rollback, e.g. when it knows the candidate version is the wrong content); INTEGRITY_POST_HEAL
 * carries the final outcome for every terminal result (healed, listener-vetoed, restore-failed, and
 * unrecoverable), so $targetVersion is null when no version was rolled back to.
 */
class AssetHealEvent extends Event
{
    use CancellableEvent;

    public function __construct(
        public readonly Asset $asset,
        public readonly ?int $targetVersion,
        public readonly ?HealOutcome $outcome = null,
    ) {}
}
