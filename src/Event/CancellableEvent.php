<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

/**
 * The veto contract shared by cancellable Asset Pilot events: a listener calls cancel() to stop the operation,
 * which also halts propagation so later listeners never act on an already-vetoed event. Used by events extending
 * Symfony's Event, whose stopPropagation() this relies on.
 */
trait CancellableEvent
{
    protected bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->stopPropagation();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
