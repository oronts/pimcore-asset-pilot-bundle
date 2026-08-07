<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

/**
 * Why a revert could not proceed. Kept free of HTTP/transport concerns so the programmatic
 * OperationReverter API stays usable outside a controller; the REST layer maps these to status codes.
 */
enum RevertFailure: string
{
    case AuditEntryNotFound = 'audit_entry_not_found';
    case NotCompleted = 'not_completed';
    case AssetNotFound = 'asset_not_found';
    case AssetLocked = 'asset_locked';
    case PermissionDenied = 'permission_denied';
    case PathConflict = 'path_conflict';
    case ExecutionFailed = 'execution_failed';
    case RecoveryRequired = 'recovery_required';
}
