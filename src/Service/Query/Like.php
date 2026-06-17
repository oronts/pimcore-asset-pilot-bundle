<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Escapes a value for use inside a bound SQL LIKE operand so user input cannot inject the `%` and `_`
 * wildcards (or the `\` escape char). Append your own trailing `%` after escaping the literal part.
 */
final class Like
{
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
