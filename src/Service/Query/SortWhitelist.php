<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Maps a request sort key to a real column via a per-endpoint allowlist, keeping the identifier off
 * the SQL string. Unknown key -> default; non-ASC order -> DESC.
 */
final class SortWhitelist
{
    /**
     * @param array<string, string> $allowed map of public sort key => real column expression
     * @return array{0: string, 1: string} [columnExpression, 'ASC'|'DESC']
     */
    public static function resolve(?string $sort, ?string $order, array $allowed, string $defaultKey): array
    {
        $key = $sort !== null && isset($allowed[$sort]) ? $sort : $defaultKey;
        $direction = strtoupper((string) $order) === 'ASC' ? 'ASC' : 'DESC';

        return [$allowed[$key], $direction];
    }
}
