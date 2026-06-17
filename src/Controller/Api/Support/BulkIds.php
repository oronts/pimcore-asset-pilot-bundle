<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

final class BulkIds
{
    public const int MAX = 1000;

    /**
     * Normalize a client-supplied id array: keep only positive integers (ints or plain digit
     * strings — never booleans, arrays, floats, or signed/spaced strings), drop non-positive ids,
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
            } elseif (is_string($value) && ctype_digit($value)) {
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
}
