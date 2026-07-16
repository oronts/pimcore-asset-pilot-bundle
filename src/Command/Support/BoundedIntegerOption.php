<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command\Support;

final class BoundedIntegerOption
{
    public static function parse(mixed $value, int $minimum, int $maximum): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);

        return is_int($parsed) ? $parsed : null;
    }
}
