<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\AppliesPlanControlEnvelope;
use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Controller\Api\Support\HandlesBulkIds;
use Oronts\AssetPilotBundle\Controller\Api\Support\ReadsPlanControlRequest;
use Oronts\AssetPilotBundle\Controller\Api\Support\ReadsRequestScalars;
use Oronts\AssetPilotBundle\Controller\Api\Support\RejectsClaimedPlan;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\PropertyType;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMetadataMutationServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetPropertyServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetSearchServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetZipServiceInterface;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Oronts\AssetPilotBundle\Service\ZipDownloadTokenStoreInterface;
use Oronts\AssetPilotBundle\Support\PropertyValue;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\Tag;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AssetManagementController
{
    use AppliesPlanControlEnvelope;
    use DecodesJsonObject;
    use HandlesBulkIds;
    use ReadsPlanControlRequest;
    use ReadsRequestScalars;
    use RejectsClaimedPlan;

    private const int MAX_TAG_LIMIT = 200;

    public function __construct(
        private readonly AssetSearchServiceInterface $searchService,
        private readonly AssetPropertyServiceInterface $propertyService,
        private readonly LoggerInterface $logger,
        private readonly AssetZipServiceInterface $zipService,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly ZipDownloadTokenStoreInterface $zipDownloads,
        private readonly ApplyPlanServiceInterface $applyPlans,
        private readonly AssetMetadataMutationServiceInterface $metadataMutations,
    ) {}

    #[Route('/assets/download-zip/prepare', name: 'oronts_asset_pilot_assets_download_zip_prepare', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function prepareZipDownload(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        $options = $this->zipOptions($data);
        if ($options instanceof JsonResponse) {
            return $options;
        }

        try {
            $token = $this->zipDownloads->issue($assetIds, $options, $this->authorization->currentActor());
        } catch (\Throwable $e) {
            $reference = $this->errorReference();
            $this->logger->error('Asset Pilot: failed to prepare zip download', [
                'reference' => $reference,
                'exception' => $e,
            ]);

            return new JsonResponse(
                ['error' => 'Failed to prepare the archive download.', 'reference' => $reference],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return new JsonResponse(['token' => $token], Response::HTTP_CREATED);
    }

    #[Route('/assets/download-zip/{token}', name: 'oronts_asset_pilot_assets_download_zip_stream', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function downloadPreparedZip(string $token): Response
    {
        $plan = $this->zipDownloads->claim($token, $this->authorization->currentActor());
        if ($plan === null) {
            return new JsonResponse(['error' => 'Download token not found or expired.'], Response::HTTP_NOT_FOUND);
        }

        return $this->streamZip($plan->assetIds, $plan->options, $this->authorization->currentActor());
    }

    #[Route('/assets/download-zip', name: 'oronts_asset_pilot_assets_download_zip', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function downloadZip(Request $request): Response
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        $options = $this->zipOptions($data);
        if ($options instanceof JsonResponse) {
            return $options;
        }

        return $this->streamZip($assetIds, $options, $this->authorization->currentActor());
    }

    /** @param list<int> $assetIds */
    private function streamZip(array $assetIds, ZipBuildOptions $options, ActorContext $actor): Response
    {

        try {
            $result = $this->zipService->buildFromAssetIds($assetIds, $options, $actor);
        } catch (\LengthException|\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: zip download failed', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to build the archive.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (!$result->hasArchive()) {
            return new JsonResponse(['error' => 'No downloadable assets in the selection.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new BinaryFileResponse($result->path);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('X-Asset-Pilot-Requested', (string) $result->requested);
        $response->headers->set('X-Asset-Pilot-Added', (string) $result->added);
        $response->headers->set('X-Asset-Pilot-Skipped', (string) $result->skipped);
        $response->headers->set('X-Asset-Pilot-Truncated', $result->truncated ? 'true' : 'false');
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'assets.zip');

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function zipOptions(array $data): ZipBuildOptions|JsonResponse
    {
        foreach (['strategy', 'thumbnail'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && !is_string($data[$key])) {
                return new JsonResponse(['error' => sprintf('%s must be a string or null.', $key)], Response::HTTP_BAD_REQUEST);
            }
        }

        return new ZipBuildOptions($data['strategy'] ?? null, $data['thumbnail'] ?? null);
    }

    #[Route('/assets/{id}/lock', name: 'oronts_asset_pilot_lock_asset', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function lockAsset(int $id): JsonResponse
    {
        $asset = Asset::getById($id);
        if ($asset === null) {
            return new JsonResponse(['error' => 'Asset not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            return new JsonResponse(['error' => 'Asset mutation is not permitted.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $observerWarnings = $this->propertyService->lockAsset($id);

            return new JsonResponse(['message' => 'Asset locked', 'assetId' => $id, 'observerWarnings' => $observerWarnings]);
        } catch (\Throwable $e) {
            $reference = $this->errorReference();
            $this->logger->error('Asset Pilot: failed to lock asset {id}', ['id' => $id, 'reference' => $reference, 'exception' => $e]);

            return new JsonResponse(['error' => 'Failed to lock the asset.', 'reference' => $reference], Response::HTTP_INTERNAL_SERVER_ERROR);
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
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            return new JsonResponse(['error' => 'Asset mutation is not permitted.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $observerWarnings = $this->propertyService->unlockAsset($id);

            return new JsonResponse(['message' => 'Asset unlocked', 'assetId' => $id, 'observerWarnings' => $observerWarnings]);
        } catch (\Throwable $e) {
            $reference = $this->errorReference();
            $this->logger->error('Asset Pilot: failed to unlock asset {id}', ['id' => $id, 'reference' => $reference, 'exception' => $e]);

            return new JsonResponse(['error' => 'Failed to unlock the asset.', 'reference' => $reference], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/assets/search', name: 'oronts_asset_pilot_assets_search', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function search(Request $request): JsonResponse
    {
        [$page, $limit] = Pagination::fromRequest($request, 200);

        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'type' => $request->query->get('type'),
            'folder' => $request->query->get('folder'),
            'objectId' => $request->query->getInt('objectId'),
            'extension' => $request->query->get('extension'),
            'referenced' => $request->query->get('referenced'),
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
    #[IsGranted('tags_assignment')]
    public function availableTags(Request $request): JsonResponse
    {
        [$page, $limit] = Pagination::fromRequest($request, self::MAX_TAG_LIMIT);
        $query = trim((string) $request->query->get('q', ''));

        try {
            $listing = $this->createTagListing();

            if ($query !== '') {
                $needle = '%' . Like::escape($query) . '%';
                $listing->setCondition('(name LIKE ?' . Like::CLAUSE . " OR CONCAT(idPath, id, '/') LIKE ?" . Like::CLAUSE . ')', [$needle, $needle]);
            }

            $listing
                ->setOrderKey(['name', 'id'])
                ->setOrder(['asc', 'asc'])
                ->setOffset(($page - 1) * $limit)
                ->setLimit($limit);

            $total = $listing->getTotalCount();
            $tags = array_map($this->serializeTag(...), $listing->getTags());

            return new JsonResponse([
                'items' => $tags,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        } catch (\Throwable $e) {
            $reference = $this->errorReference();
            $this->logger->error('Asset Pilot: failed to load tags', ['reference' => $reference, 'exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to load tags.', 'reference' => $reference],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    protected function createTagListing(): Tag\Listing
    {
        return new Tag\Listing();
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
    #[IsGranted('tags_assignment')]
    public function bulkTag(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $input = $this->bulkTagInput($data);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->executePlannedMetadataMutation(
            $data,
            count($input['assetIds']),
            'tagged',
            fn (): ApplyPlan => $this->metadataMutations->tagPlan(
                $this->authorization->currentActor(),
                $input['assetIds'],
                $input['tagIds'],
                $input['replace'],
            ),
            fn (array $expected): array => $this->applyTagMutation($input, $expected),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{assetIds: list<int>, tagIds: list<int>, replace: bool}|JsonResponse
     */
    private function bulkTagInput(array $data): array|JsonResponse
    {
        $replace = $data['replace'] ?? false;
        if (!is_bool($replace)) {
            return new JsonResponse(['error' => 'replace must be a boolean.'], Response::HTTP_BAD_REQUEST);
        }

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }
        $tagIds = $this->validatedBulkIds($data['tagIds'] ?? null, 'tagIds');
        if ($tagIds instanceof JsonResponse) {
            return $tagIds;
        }

        sort($assetIds, SORT_NUMERIC);
        sort($tagIds, SORT_NUMERIC);

        return $this->mutationPermissionError($assetIds)
            ?? ['assetIds' => $assetIds, 'tagIds' => $tagIds, 'replace' => $replace];
    }

    /**
     * @param array{assetIds: list<int>, tagIds: list<int>, replace: bool} $input
     * @param array<string, string> $expectedFingerprints
     * @return array<string, mixed>
     */
    private function applyTagMutation(array $input, array $expectedFingerprints): array
    {
        $result = $this->metadataMutations->applyTags(
            $input['assetIds'],
            $input['tagIds'],
            $input['replace'],
            $expectedFingerprints,
        );
        $this->logger->info('Asset Pilot: bulk tagged {count} assets with {tags} tags (replace: {replace})', [
            'count' => count($input['assetIds']),
            'tags' => count($input['tagIds']),
            'replace' => $input['replace'] ? 'yes' : 'no',
        ]);

        return [
            'tagged' => $result['tagged'],
            'failed' => $result['failed'],
            'errors' => empty($result['errors']) ? (object) [] : $result['errors'],
            'observerWarnings' => $result['observerWarnings'],
        ];
    }

    #[Route('/assets/bulk-property', name: 'oronts_asset_pilot_bulk_property', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkProperty(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $input = $this->bulkPropertyInput($data);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->executePlannedMetadataMutation(
            $data,
            count($input['assetIds']),
            'updated',
            fn (): ApplyPlan => $this->metadataMutations->propertyPlan(
                $this->authorization->currentActor(),
                $input['assetIds'],
                $input['name'],
                $input['type'],
                $input['value'],
            ),
            fn (array $expected): array => $this->applyPropertyMutation($input, $expected),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{assetIds: list<int>, name: string, type: string, value: bool|string}|JsonResponse
     */
    private function bulkPropertyInput(array $data): array|JsonResponse
    {
        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        $name = $this->requestString($data, 'name', '');
        if ($name instanceof JsonResponse) {
            return $name;
        }
        $name = trim($name);
        if ($name === '') {
            return new JsonResponse(['error' => 'name is required'], Response::HTTP_BAD_REQUEST);
        }
        $type = $this->requestString($data, 'type', PropertyType::Text->value);
        if ($type instanceof JsonResponse) {
            return $type;
        }
        $type = trim($type);
        if (!in_array($type, PropertyType::values(), true)) {
            return new JsonResponse(['error' => 'type must be one of: ' . implode(', ', PropertyType::values())], Response::HTTP_BAD_REQUEST);
        }
        $value = $data['data'] ?? '';
        if (!is_scalar($value)) {
            return new JsonResponse(['error' => 'data must be a string, number, or boolean'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $value = PropertyValue::normalize(PropertyType::from($type), $value);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => sprintf('data is not a valid %s value.', $type)], Response::HTTP_BAD_REQUEST);
        }

        sort($assetIds, SORT_NUMERIC);

        return $this->mutationPermissionError($assetIds) ?? [
            'assetIds' => $assetIds,
            'name' => $name,
            'type' => $type,
            'value' => $value,
        ];
    }

    /**
     * @param array{assetIds: list<int>, name: string, type: string, value: bool|string} $input
     * @param array<string, string> $expectedFingerprints
     * @return array<string, mixed>
     */
    private function applyPropertyMutation(array $input, array $expectedFingerprints): array
    {
        $result = $this->metadataMutations->applyProperty(
            $input['assetIds'],
            $input['name'],
            $input['type'],
            $input['value'],
            $expectedFingerprints,
        );

        return [
            'updated' => $result['updated'],
            'failed' => $result['failed'],
            'errors' => empty($result['errors']) ? (object) [] : $result['errors'],
            'observerWarnings' => $result['observerWarnings'],
        ];
    }

    /** @param list<int> $assetIds */
    private function mutationPermissionError(array $assetIds): ?JsonResponse
    {
        $forbidden = $this->firstForbiddenAsset($assetIds);

        return $forbidden === null
            ? null
            : new JsonResponse(['error' => 'Asset mutation is not permitted.', 'assetId' => $forbidden], Response::HTTP_FORBIDDEN);
    }

    /** @param list<int> $assetIds */
    protected function firstForbiddenAsset(array $assetIds): ?int
    {
        foreach ($assetIds as $assetId) {
            $asset = Asset::getById($assetId);
            if ($asset === null || !$this->authorization->isAllowed($asset, 'publish')) {
                return $assetId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(): ApplyPlan $plan
     * @param callable(array<string, string>): array<string, mixed> $apply
     */
    private function executePlannedMetadataMutation(
        array $data,
        int $requested,
        string $counter,
        callable $plan,
        callable $apply,
    ): JsonResponse {
        $control = $this->readPlanControl($data);
        if ($control instanceof JsonResponse) {
            return $control;
        }

        try {
            return $this->executeMetadataPlan(
                $control['dryRun'],
                $control['token'],
                $requested,
                $counter,
                $plan,
                $apply,
            );
        } catch (StaleApplyPlanException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                $control['dryRun'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT,
            );
        } catch (\Throwable $e) {
            return $this->metadataMutationFailure($e);
        }
    }

    /**
     * @param callable(): ApplyPlan $plan
     * @param callable(array<string, string>): array<string, mixed> $apply
     */
    private function executeMetadataPlan(
        bool $dryRun,
        string $token,
        int $requested,
        string $counter,
        callable $plan,
        callable $apply,
    ): JsonResponse {
        $currentPlan = $plan();
        if ($dryRun) {
            return $this->metadataPreviewResponse($currentPlan, $counter, $requested);
        }

        $rejection = $this->rejectClaimedPlan($this->applyPlans->claim($token, $currentPlan));
        if ($rejection !== null) {
            return $rejection;
        }

        return new JsonResponse($this->withPlanControl(
            $apply($currentPlan->fingerprintMap()),
            false,
            null,
            $requested,
        ));
    }

    private function metadataPreviewResponse(ApplyPlan $plan, string $counter, int $requested): JsonResponse
    {
        return new JsonResponse($this->withPlanControl([
            $counter => 0,
            'failed' => 0,
            'errors' => (object) [],
            'observerWarnings' => [],
        ], true, $this->applyPlans->issue($plan), $requested));
    }


    private function metadataMutationFailure(\Throwable $exception): JsonResponse
    {
        $reference = $this->errorReference();
        $this->logger->error('Asset Pilot: planned metadata mutation failed', [
            'reference' => $reference,
            'exception' => $exception,
        ]);

        return new JsonResponse(
            ['error' => 'Failed to mutate asset metadata.', 'reference' => $reference],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    private function errorReference(): string
    {
        return bin2hex(random_bytes(8));
    }
}
