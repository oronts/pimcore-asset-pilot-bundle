<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum RulePreviewPlanStatus
{
    case Valid;
    case Malformed;
    case Stale;
}
