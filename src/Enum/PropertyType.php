<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum PropertyType: string
{
    case Text = 'text';
    case Bool = 'bool';
    case Select = 'select';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
