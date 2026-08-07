<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\StorageTrendServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class StorageController
{
    private const int MAX_POINTS = 365;

    public function __construct(
        protected readonly StorageTrendServiceInterface $trends,
        protected readonly LoggerInterface $logger,
        private readonly ApiDateFormatterInterface $dates,
    ) {}

    /**
     * Read-only unused-storage trend from the snapshot table (never scans; the capture is the
     * maintenance task / asset-pilot:capture-storage-snapshot).
     */
    #[Route('/storage/trends', name: 'oronts_asset_pilot_storage_trends', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function trends(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        $limit = min(self::MAX_POINTS, max(1, $request->query->getInt('limit', 90)));

        try {
            $resolvedType = $type !== null && $type !== '' ? (string) $type : null;

            return new JsonResponse([
                'type' => $resolvedType,
                'items' => array_map(fn (array $point): array => [
                    ...$point,
                    'capturedAt' => $this->dates->fromDatabase($point['capturedAt']),
                ], $this->trends->trend($resolvedType, $limit)),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read storage trends.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to read storage trends.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
