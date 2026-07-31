<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Support;

/**
 * Sanitizes a client-supplied id list down to clean positive integers. Shared by the REST layer (JSON
 * body / query arrays via clean(), comma-separated query params via fromCsv()) and the CLI commands
 * (comma-separated options via fromCsv()), so HTTP and console id parsing follow one contract and
 * cannot drift.
 *
 * @internal Not a documented extension seam; relocate-safe.
 */
class BulkIds
{
    public const int MAX = 1000;

    /**
     * Normalize a client-supplied id array: keep only positive integers (ints or canonical positive
     * digit strings, no leading zeros — never booleans, arrays, floats, signed/spaced/`0`-prefixed
     * strings, nor digit strings above the platform integer range, matching the OpenAPI IdList item
     * pattern), drop non-positive ids,
     * and de-duplicate preserving first-seen order. Stops one past self::MAX so a caller can reject
     * an oversized request without this doing unbounded work first. Returns a clean list (empty when
     * the input is not a usable array). Callers reject `[]` and `count > self::MAX` with 400.
     *
     * @return list<int>
     */
    public static function clean(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        $seen = [];
        foreach ($raw as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && (string) (int) $value === $value) {
                // The round-trip drops digit strings above the platform integer range (they would
                // otherwise saturate to PHP_INT_MAX), matching ReadsRequestScalars::parsePositiveInt.
                $id = (int) $value;
            } else {
                continue;
            }

            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;

            if (count($ids) > self::MAX) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Parse a comma-separated id string (a CLI option or a query param) into clean positive ints.
     * Each token is trimmed first, so "1, 2, 3" is accepted; a non-digit token ("5abc") is rejected,
     * never silently coerced to a number. Returns [] for null/blank input.
     *
     * @return list<int>
     */
    public static function fromCsv(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        return self::clean(array_map('trim', explode(',', $csv)));
    }
}
