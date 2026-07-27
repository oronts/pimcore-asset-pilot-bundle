<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum SingleRunOutcomeKind
{
    case Completed;
    case ClaimConflict;
    case LeaseLost;
    case Stale;
    case Failed;
}
