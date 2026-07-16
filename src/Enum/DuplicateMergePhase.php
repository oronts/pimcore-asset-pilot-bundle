<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum DuplicateMergePhase: string
{
    case Prepared = 'prepared';
    case Repointing = 'repointing';
    case Repointed = 'repointed';
    case Disposing = 'disposing';
    case Committed = 'committed';
    case Blocked = 'blocked';
    case Failed = 'failed';
}
