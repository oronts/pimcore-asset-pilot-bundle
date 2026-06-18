<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum HealOutcome: string
{
    case Healed = 'healed';                       // rolled back to a renderable version (or would, in dry-run)
    case AlreadyRenderable = 'already_renderable'; // the live binary is fine — nothing to do
    case Unrecoverable = 'unrecoverable';         // broken, and no version renders
    case Unverifiable = 'unverifiable';           // could not confirm broken (tool absent / unsupported) — never healed
    case Skipped = 'skipped';                     // a listener cancelled the heal
}
