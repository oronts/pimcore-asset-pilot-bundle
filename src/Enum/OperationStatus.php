<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case ActionFailed = 'action_failed';
}
