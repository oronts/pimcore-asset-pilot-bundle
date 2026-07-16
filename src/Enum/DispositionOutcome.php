<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum DispositionOutcome: string
{
    case Quarantined = 'quarantined';
    case Deleted = 'deleted';
    case LeftReferenced = 'left_referenced';
    case LeftError = 'left_error';
    case Blocked = 'blocked';
    case Skipped = 'skipped';
}
