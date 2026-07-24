<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationRunStatus: string
{
    // Producer-outbox state: committed with the (possibly consumer-owned) source transaction but not yet
    // published. The dispatch relay publishes committed pending runs and transitions them to Queued.
    case PendingDispatch = 'pending_dispatch';
    case Queued = 'queued';
    case Running = 'running';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Blocked = 'blocked';
    case Partial = 'partial';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Completed, self::Blocked, self::Partial, self::Failed], true);
    }
}
