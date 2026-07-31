<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Parse the shared dryRun/planToken mutation controls from a decoded request body, used by every controller that
 * previews then applies a reviewed plan, so the request contract stays identical across those endpoints.
 */
trait ReadsPlanControlRequest
{
    /**
     * @param array<string, mixed> $data
     * @return array{dryRun: bool, token: string}|JsonResponse a validated control pair, or the error response
     */
    private function readPlanControl(array $data): array|JsonResponse
    {
        $dryRun = $data['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return new JsonResponse(['error' => 'dryRun must be a boolean.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $token = is_string($data['planToken'] ?? null) ? $data['planToken'] : '';
        if (!$dryRun && $token === '') {
            return new JsonResponse(['error' => 'A planToken from a fresh dry-run preview is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return ['dryRun' => $dryRun, 'token' => $token];
    }
}
