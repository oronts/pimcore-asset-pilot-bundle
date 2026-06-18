<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum IntegrityStatus: string
{
    case Renderable = 'renderable';
    case Broken = 'broken';
    // The binary could not be verified (e.g. the required tool is absent). Never treated as broken,
    // so the feature never destroys data because a checker could not run.
    case Unverifiable = 'unverifiable';
}
