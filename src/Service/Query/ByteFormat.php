<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Human-readable byte sizes, shared by the size-reporting commands and services. A pure value helper:
 * call it directly (no mocking needed to test). Not final and the unit list is protected, so a
 * project may subclass it (`static::UNITS`) to localize or extend the units; the unit index is
 * clamped so a petabyte-plus size cannot index past the list.
 */
class ByteFormat
{
    protected const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];

    public static function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $i = min((int) floor(log($bytes, 1024)), count(static::UNITS) - 1);

        return round($bytes / (1024 ** $i), 2) . ' ' . static::UNITS[$i];
    }
}
