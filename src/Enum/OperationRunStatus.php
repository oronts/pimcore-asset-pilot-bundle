<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationRunStatus: string
{
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
