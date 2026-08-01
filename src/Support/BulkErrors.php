<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Support;

/**
 * Render a bulk per-item error map for a JSON response. The BulkErrors OpenAPI schema is an object, so an empty
 * map must serialize as {} (an empty PHP array would encode as []), keeping every bulk endpoint's success
 * response decodable as a Record<string, string> by a strict client or SDK.
 */
final class BulkErrors
{
    /**
     * @param array<int|string, string> $errors
     * @return array<int|string, string>|\stdClass
     */
    public static function forResponse(array $errors): array|\stdClass
    {
        return $errors === [] ? new \stdClass() : $errors;
    }
}
