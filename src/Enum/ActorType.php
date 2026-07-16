<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case Anonymous = 'anonymous';
}
