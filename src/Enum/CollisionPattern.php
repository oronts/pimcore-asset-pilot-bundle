<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum CollisionPattern: string
{
    case Counter = 'counter';
    case Timestamp = 'timestamp';
    case Uuid = 'uuid';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
