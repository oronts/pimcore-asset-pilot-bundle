<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum BulkRunOutcomeKind
{
    case Completed;
    case OwnershipLost;
    case Failed;
}
