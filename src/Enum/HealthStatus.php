<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';

    /** Higher is worse; used to roll individual checks up into an overall status. */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }
}
