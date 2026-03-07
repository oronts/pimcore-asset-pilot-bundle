<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum MoveStrategy: string
{
    case Always = 'always';
    case FirstAssignment = 'first_assignment';
    case Callback = 'callback';
}
