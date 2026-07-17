<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum DependencyProjectionState: string
{
    case BootstrapRequired = 'bootstrap_required';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';
}
