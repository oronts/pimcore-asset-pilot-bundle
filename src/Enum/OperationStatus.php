<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case RecoveryRequired = 'recovery_required';
    case Completed = 'completed';
    case CompletedWithObserverError = 'completed_with_observer_error';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
