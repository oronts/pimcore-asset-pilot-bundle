<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class IntegrityController
{
    /**
     * Each checked asset is loaded and render-tested, so the per-request work is hard-capped: both
     * the scan page and an explicit id list are bounded to MAX_ITEMS. A whole-catalog sweep belongs
     * on the CLI / async, never a single request.
     */
    private const int MAX_ITEMS = 50;

    public function __construct(
        protected readonly AssetIntegrityService $integrity,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/integrity', name: 'oronts_asset_pilot_integrity', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function brokenAssets(Request $request): JsonResponse
    {
        if ($request->query->has('ids')) {
            $parsed = array_values(array_filter(
                array_map('intval', explode(',', (string) $request->query->get('ids'))),
                static fn (int $id): bool => $id > 0,
            ));
            if ($parsed === []) {
                return new JsonResponse(['error' => 'ids must list one or more positive asset ids.'], JsonResponse::HTTP_BAD_REQUEST);
            }
            if (count($parsed) > self::MAX_ITEMS) {
                return new JsonResponse(['error' => sprintf('Too many ids; check at most %d at once.', self::MAX_ITEMS)], JsonResponse::HTTP_BAD_REQUEST);
            }

            try {
                return new JsonResponse($this->integrity->checkAssets($parsed));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to check asset integrity.', ['exception' => $e]);

                return new JsonResponse(['error' => 'Failed to check asset integrity.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_ITEMS, max(1, $request->query->getInt('limit', 25)));

        $filters = array_filter([
            'folder' => $request->query->get('folder'),
            'type' => $request->query->get('type'),
            'extension' => $request->query->get('extension'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        try {
            return new JsonResponse($this->integrity->findBroken($filters, $page, $limit));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scan asset integrity.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to scan asset integrity.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
