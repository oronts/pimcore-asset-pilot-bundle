<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum ConfidenceLevel: string
{
    // Order is the classification priority: Protected and HistoricallyUsed win over the age buckets.
    case Protected = 'protected';
    case HistoricallyUsed = 'historically_used';
    case RecentlyUploaded = 'recently_uploaded';
    case ProbablyUnused = 'probably_unused';
    case DefinitelyUnused = 'definitely_unused';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
