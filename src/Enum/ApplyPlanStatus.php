<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum ApplyPlanStatus
{
    case Valid;
    case Claimed;
    case Malformed;
    case Stale;
    case AlreadyClaimed;
}
