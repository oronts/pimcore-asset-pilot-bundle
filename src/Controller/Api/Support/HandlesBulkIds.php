<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait HandlesBulkIds
{
    /**
     * Sanitize a client id array and enforce the per-request cap, or return a 400 to send back.
     *
     * @return list<int>|JsonResponse
     */
    protected function validatedBulkIds(mixed $raw, string $field): array|JsonResponse
    {
        $ids = BulkIds::clean($raw);

        if ($ids === []) {
            return new JsonResponse(
                ['error' => sprintf('%s must be a non-empty array of positive integers', $field)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (count($ids) > BulkIds::MAX) {
            return new JsonResponse(
                ['error' => sprintf('Too many %s: %d (max %d per request). Narrow your selection.', $field, count($ids), BulkIds::MAX)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $ids;
    }
}
