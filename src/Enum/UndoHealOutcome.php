<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum UndoHealOutcome: string
{
    case WouldReverse = 'would_reverse';
    case Reversed = 'reversed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
