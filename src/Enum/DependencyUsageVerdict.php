<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum DependencyUsageVerdict: string
{
    case Safe = 'safe';
    case Referenced = 'referenced';
    case Unknown = 'unknown';
}
