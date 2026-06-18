<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\HandlesBulkIds;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UnusedAssetsController
{
    use HandlesBulkIds;

    public function __construct(
        private readonly UnusedAssetFinderInterface $unusedAssetFinder,
        private readonly QuarantineService $quarantineService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/unused-assets', name: 'oronts_asset_pilot_unused_assets', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));

        if ($request->query->has('minSize') || $request->query->has('maxSize')) {
            return new JsonResponse([
                'error' => 'Size filtering (minSize/maxSize) is not supported: the Pimcore assets '
                    . 'table has no size column. Filter by type, extension, folder, or date instead.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $filters = array_filter([
            'type' => $request->query->get('type'),
            'extension' => $request->query->get('extension'),
            'before' => $request->query->get('before'),
            'after' => $request->query->get('after'),
            'folder' => $request->query->get('folder'),
            'confidence' => $request->query->get('confidence'),
        ], static fn ($v) => $v !== null && $v !== '');

        $result = $this->unusedAssetFinder->findUnused(
            $filters,
            $page,
            $limit,
            $request->query->get('sort'),
            $request->query->get('order'),
        );

        return new JsonResponse($result);
    }

    #[Route('/unused-assets/stats', name: 'oronts_asset_pilot_unused_assets_stats', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function stats(): JsonResponse
    {
        return new JsonResponse($this->unusedAssetFinder->getUnusedStats());
    }

    #[Route('/unused-assets/bulk-delete', name: 'oronts_asset_pilot_unused_assets_bulk_delete', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        $this->logger->info('Asset Pilot: bulk delete requested for {count} unused assets', [
            'count' => count($assetIds),
        ]);

        $result = $this->unusedAssetFinder->deleteAssets($assetIds);

        return new JsonResponse($result);
    }

    #[Route('/unused-assets/bulk-move', name: 'oronts_asset_pilot_unused_assets_bulk_move', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkMove(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $targetFolder = $data['targetFolder'] ?? '';

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        if (empty($targetFolder)) {
            return new JsonResponse(['error' => 'targetFolder is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Asset Pilot: bulk move requested for {count} unused assets to {folder}', [
            'count' => count($assetIds),
            'folder' => $targetFolder,
        ]);

        $result = $this->unusedAssetFinder->moveAssets($assetIds, $targetFolder);

        return new JsonResponse($result);
    }

    #[Route('/unused-assets/bulk-quarantine', name: 'oronts_asset_pilot_unused_assets_bulk_quarantine', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkQuarantine(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        $this->logger->info('Asset Pilot: bulk quarantine requested for {count} unused assets', [
            'count' => count($assetIds),
        ]);

        return new JsonResponse($this->quarantineService->quarantine($assetIds));
    }
}
