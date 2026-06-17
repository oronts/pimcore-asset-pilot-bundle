<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\HandlesBulkIds;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\PropertyType;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use Oronts\AssetPilotBundle\Service\AssetSearchService;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\Tag;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AssetManagementController
{
    use HandlesBulkIds;

    private const int MAX_TAGS = 500;

    public function __construct(
        private readonly AssetSearchService $searchService,
        private readonly AssetPropertyService $propertyService,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    #[Route('/assets/{id}/lock', name: 'oronts_asset_pilot_lock_asset', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function lockAsset(int $id): JsonResponse
    {
        $asset = Asset::getById($id);
        if ($asset === null) {
            return new JsonResponse(['error' => 'Asset not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->propertyService->lockAsset($id, $asset->getRealFullPath());

            return new JsonResponse(['message' => 'Asset locked', 'assetId' => $id]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to lock asset {id}: {error}', ['id' => $id, 'error' => $e->getMessage()]);

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/assets/{id}/lock', name: 'oronts_asset_pilot_unlock_asset', methods: ['DELETE'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function unlockAsset(int $id): JsonResponse
    {
        $asset = Asset::getById($id);
        if ($asset === null) {
            return new JsonResponse(['error' => 'Asset not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->propertyService->unlockAsset($id);

            return new JsonResponse(['message' => 'Asset unlocked', 'assetId' => $id]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to unlock asset {id}: {error}', ['id' => $id, 'error' => $e->getMessage()]);

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/assets/by-object/{objectId}', name: 'oronts_asset_pilot_assets_by_object', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function assetsByObject(int $objectId, Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));
        $type = $request->query->get('type');

        return new JsonResponse($this->searchService->findByObject(
            $objectId,
            $page,
            $limit,
            $type,
            $request->query->get('sort'),
            $request->query->get('order'),
        ));
    }

    #[Route('/assets/search', name: 'oronts_asset_pilot_assets_search', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function search(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));

        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'type' => $request->query->get('type'),
            'folder' => $request->query->get('folder'),
            'objectId' => $request->query->getInt('objectId'),
        ];

        return new JsonResponse($this->searchService->search(
            $filters,
            $page,
            $limit,
            $request->query->get('sort'),
            $request->query->get('order'),
        ));
    }

    #[Route('/assets/tags', name: 'oronts_asset_pilot_available_tags', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function availableTags(): JsonResponse
    {
        try {
            $listing = new Tag\Listing();
            $listing->setLimit(self::MAX_TAGS);
            $tags = array_map($this->serializeTag(...), $listing->getTags());

            if (count($tags) === self::MAX_TAGS) {
                $this->logger->warning('Asset Pilot: tag list capped at {max}; some tags are not returned.', ['max' => self::MAX_TAGS]);
            }

            return new JsonResponse($tags);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to load tags: {error}', ['error' => $e->getMessage()]);

            return new JsonResponse([]);
        }
    }

    #[Route('/assets/{id}/tags', name: 'oronts_asset_pilot_asset_tags', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function assetTags(int $id): JsonResponse
    {
        try {
            $tags = array_map($this->serializeTag(...), Tag::getTagsForElement('asset', $id));

            return new JsonResponse($tags);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get asset tags: {error}', ['error' => $e->getMessage()]);

            return new JsonResponse([]);
        }
    }

    /**
     * @return array{id: int|null, name: string, parentId: int|null, path: string}
     */
    private function serializeTag(Tag $tag): array
    {
        return [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'parentId' => $tag->getParentId(),
            'path' => $tag->getFullIdPath(),
        ];
    }

    #[Route('/assets/bulk-tag', name: 'oronts_asset_pilot_bulk_tag', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkTag(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $replace = (bool) ($data['replace'] ?? false);

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        $tagIds = $this->validatedBulkIds($data['tagIds'] ?? null, 'tagIds');
        if ($tagIds instanceof JsonResponse) {
            return $tagIds;
        }

        try {
            Tag::batchAssignTagsToElement('asset', $assetIds, $tagIds, $replace);

            $this->logger->info('Asset Pilot: bulk tagged {count} assets with {tags} tags (replace: {replace})', [
                'count' => count($assetIds),
                'tags' => count($tagIds),
                'replace' => $replace ? 'yes' : 'no',
            ]);

            $this->eventDispatcher->dispatch(
                new AssetMutationEvent($assetIds, 'tag', ['tagIds' => $tagIds, 'replace' => $replace]),
                AssetPilotEvents::ASSETS_TAGGED,
            );

            return new JsonResponse([
                'tagged' => count($assetIds),
                'failed' => 0,
                'errors' => (object) [],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: bulk tag failed: {error}', ['error' => $e->getMessage()]);

            return new JsonResponse([
                'tagged' => 0,
                'failed' => count($assetIds),
                'errors' => ['_global' => $e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/assets/bulk-property', name: 'oronts_asset_pilot_bulk_property', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkProperty(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $type = trim((string) ($data['type'] ?? PropertyType::Text->value));
        $value = $data['data'] ?? '';

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        if ($name === '') {
            return new JsonResponse(['error' => 'name is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($type, PropertyType::values(), true)) {
            return new JsonResponse(['error' => 'type must be one of: ' . implode(', ', PropertyType::values())], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->propertyService->bulkSetProperty($assetIds, $name, $type, $value);

        return new JsonResponse([
            'updated' => $result['updated'],
            'failed' => $result['failed'],
            'errors' => empty($result['errors']) ? (object) [] : $result['errors'],
        ]);
    }
}
