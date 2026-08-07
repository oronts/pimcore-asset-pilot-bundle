<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum UndoHealReason: string
{
    case AlreadyUndone = 'already_undone';
    case Superseded = 'superseded';
    case AssetBusy = 'asset_busy';
    case AssetNotFound = 'asset_not_found';
    case NotPermitted = 'not_permitted';
    case AssetLocked = 'asset_locked';
    case ExcludedFolder = 'excluded_folder';
    case NoReversibleHeal = 'no_reversible_heal';
    case VersionMissing = 'version_missing';
    case AssetChanged = 'asset_changed';
    case RestoreFailed = 'restore_failed';
    case LogUpdateFailed = 'log_update_failed';
}
