<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationRunKind: string
{
    case Organize = 'organize';
    case Reorganize = 'reorganize';
    case Replay = 'replay';
    case DuplicateMerge = 'duplicate-merge';
    case Simulation = 'simulation';
}
