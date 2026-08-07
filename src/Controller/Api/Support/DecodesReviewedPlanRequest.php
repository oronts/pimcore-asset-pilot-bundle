<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

trait DecodesReviewedPlanRequest
{
    use DecodesJsonObject;

    /** @return array{limit: int, apply: bool, planToken: ?string}|JsonResponse */
    protected function decodeReviewedPlanRequest(Request $request): array|JsonResponse
    {
        $body = $this->decodeJsonObject($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $limit = $body['limit'] ?? 100;
        if (!is_int($limit) || $limit < 1 || $limit > 1_000) {
            return new JsonResponse(['error' => 'The limit must be an integer between 1 and 1000.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $apply = $body['apply'] ?? false;
        if (!is_bool($apply)) {
            return new JsonResponse(['error' => 'The apply value must be a boolean.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $token = $body['planToken'] ?? null;
        if ($apply && (!is_string($token) || $token === '')) {
            return new JsonResponse(['error' => 'Applying requires the planToken from a matching preview.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (!$apply && $token !== null) {
            return new JsonResponse(['error' => 'planToken is only valid when apply is true.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return ['limit' => $limit, 'apply' => $apply, 'planToken' => $token];
    }
}
