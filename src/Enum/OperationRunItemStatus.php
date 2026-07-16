<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationRunItemStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Blocked = 'blocked';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Blocked, self::Skipped, self::Failed, self::Cancelled], true);
    }
}
