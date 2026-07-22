<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Symfony\Component\HttpFoundation\Request;

class Pagination
{
    /**
     * Parse and clamp the ?page/?limit query params into [page, limit]: page is at least 1, limit is
     * bounded to [1, maxLimit]. Each endpoint passes its own cap and default so the bounds stay local.
     * Casts tolerantly (non-numeric input coerces to 0, then clamps) rather than rejecting it, so a
     * stray ?page=foo defaults instead of 400-ing, matching the long-standing controller behavior.
     *
     * @return array{0: int, 1: int}
     */
    public static function fromRequest(Request $request, int $maxLimit, int $defaultLimit = 50): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min($maxLimit, max(1, (int) $request->query->get('limit', $defaultLimit)));

        return [$page, $limit];
    }
}
