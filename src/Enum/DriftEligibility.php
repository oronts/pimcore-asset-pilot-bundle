<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum DriftEligibility: string
{
    case NoKnownBlock = 'no_known_block';
    case Blocked = 'blocked';
    case RuntimeCheckRequired = 'runtime_check_required';
}
