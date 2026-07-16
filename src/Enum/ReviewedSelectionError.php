<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum ReviewedSelectionError
{
    case SelectionTooLarge;
    case MutationForbidden;
    case MissingPlanToken;
    case MalformedPlanToken;
    case StalePlan;
    case PreflightFailed;
    case ExecutionFailed;
}
