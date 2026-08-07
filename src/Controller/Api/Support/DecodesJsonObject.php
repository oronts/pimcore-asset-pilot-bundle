<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

trait DecodesJsonObject
{
    /** @return array<string, mixed>|JsonResponse */
    protected function decodeJsonObject(Request $request, bool $allowEmpty = false): array|JsonResponse
    {
        $content = $request->getContent();
        if ($allowEmpty && trim($content) === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($data) || !str_starts_with(ltrim($content), '{')) {
            return new JsonResponse(['error' => 'Request body must be a JSON object.'], Response::HTTP_BAD_REQUEST);
        }

        return $data;
    }
}
