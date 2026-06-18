<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class QuarantineController
{
    public function __construct(
        protected readonly QuarantineService $quarantineService,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/quarantine', name: 'oronts_asset_pilot_quarantine_list', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));

        return new JsonResponse($this->quarantineService->listQuarantined($page, $limit));
    }

    #[Route('/quarantine/{assetId}/restore', name: 'oronts_asset_pilot_quarantine_restore', methods: ['POST'], requirements: ['assetId' => '\d+'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function restore(int $assetId): JsonResponse
    {
        try {
            if (!$this->quarantineService->restore($assetId)) {
                return new JsonResponse(['error' => 'Asset is not quarantined or no longer exists.'], JsonResponse::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['message' => 'Asset restored.', 'assetId' => $assetId]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to restore asset {id} from quarantine.', ['id' => $assetId, 'exception' => $e]);

            return new JsonResponse(['error' => 'Failed to restore the asset.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
