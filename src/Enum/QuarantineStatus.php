<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum QuarantineStatus: string
{
    case Pending = 'pending';
    case Committed = 'committed';
}
