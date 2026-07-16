<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationKind: string
{
    case Move = 'move';
    case Revert = 'revert';
}
