<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Escapes a value for use inside a bound SQL LIKE operand so user input cannot inject the `%` and `_`
 * wildcards (or the escape char itself). Append your own trailing `%` after escaping the literal part,
 * and append {@see self::CLAUSE} to the LIKE operand so the ESCAPE char is declared explicitly.
 */
class Like
{
    /**
     * Explicit LIKE escape character. Deliberately not `\`: whether a backslash is itself special in a
     * LIKE pattern is engine- and sql_mode-dependent (MySQL treats it as the default escape, SQLite has
     * no default at all), so a `\`-based pattern silently stopped escaping `_`/`%` on SQLite. `!` has no
     * special meaning in either dialect and needs no quoting inside the ESCAPE clause.
     */
    public const string ESCAPE_CHAR = '!';

    /** SQL fragment to append after every LIKE operand fed an escape()d value, e.g. `'a.path LIKE :p' . Like::CLAUSE`. */
    public const string CLAUSE = " ESCAPE '!'";

    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
