<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum ObserverAuditReconciliationStatus: string
{
    case Recorded = 'recorded';
    case NotApplicable = 'not_applicable';
    case Deferred = 'deferred';
}
