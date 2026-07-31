<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\AppliesPlanControlEnvelope;
use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Controller\Api\Support\HandlesBulkIds;
use Oronts\AssetPilotBundle\Controller\Api\Support\StreamsCsv;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\QuarantineServiceInterface;
use Oronts\AssetPilotBundle\Service\Query\DateFilters;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Oronts\AssetPilotBundle\Service\StorageTrendServiceInterface;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UnusedAssetsController
{
    use AppliesPlanControlEnvelope;
    use DecodesJsonObject;
    use HandlesBulkIds;
    use StreamsCsv;


    public function __construct(
        private readonly UnusedAssetFinderInterface $unusedAssetFinder,
        private readonly QuarantineServiceInterface $quarantineService,
        private readonly LoggerInterface $logger,
        private readonly StorageTrendServiceInterface $storageTrend,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly ApplyPlanServiceInterface $applyPlans,
        private readonly AssetMutationFingerprintService $mutationFingerprints,
    ) {}

    #[Route('/unused-assets', name: 'oronts_asset_pilot_unused_assets', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        [$page, $limit] = Pagination::fromRequest($request, 200);

        if ($request->query->has('minSize') || $request->query->has('maxSize')) {
            return new JsonResponse([
                'error' => 'Size filtering (minSize/maxSize) is not supported: the Pimcore assets '
                    . 'table has no size column. Filter by type, extension, folder, or date instead.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $filters = $this->filters($request);
        try {
            $this->validateFilters($filters);
            $result = $this->unusedAssetFinder->findUnused(
                $filters,
                $page,
                $limit,
                $request->query->get('sort'),
                $request->query->get('order'),
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($result);
    }

    #[Route('/unused-assets/stats', name: 'oronts_asset_pilot_unused_assets_stats', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function stats(): JsonResponse
    {
        $stats = $this->authorization->currentActor()->type === ActorType::System
            ? $this->storageTrend->latestUnusedStats() ?? $this->unusedAssetFinder->getUnusedStatsCached()
            : $this->unusedAssetFinder->getUnusedStatsCached();

        return new JsonResponse($stats);
    }

    /**
     * Stream the unused-asset listing as CSV (id, path, type, size, modified), honoring the same
     * filters as the list endpoint, paged lazily so it stays memory-flat.
     */
    #[Route('/unused-assets/export', name: 'oronts_asset_pilot_unused_assets_export', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(Request $request): Response
    {
        if ($request->query->has('minSize') || $request->query->has('maxSize')) {
            return new JsonResponse([
                'error' => 'Size filtering (minSize/maxSize) is not supported: the Pimcore assets '
                    . 'table has no size column. Filter by type, extension, folder, or date instead.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $filters = $this->filters($request);
        try {
            $this->validateFilters($filters);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $rows = (function () use ($filters): \Generator {
            $source = $this->unusedAssetFinder->iterateForExport($filters);
            foreach ($source as $item) {
                yield [
                    $item['id'] ?? '',
                    $item['full_path'] ?? '',
                    $item['type'] ?? '',
                    $item['file_size'] ?? '',
                    $item['modified_at'] ?? '',
                ];
            }

            return $source->getReturn();
        })();

        return $this->streamCsv(
            'asset-pilot-unused-' . date('Y-m-d') . '.csv',
            ['ID', 'Path', 'Type', 'File Size (bytes)', 'Modified'],
            $rows,
        );
    }

    #[Route('/unused-assets/bulk-delete', name: 'oronts_asset_pilot_unused_assets_bulk_delete', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        return $this->executePlannedMutation(
            $data,
            $assetIds,
            'unused-delete',
            [],
            'deleted',
            fn (): array => $this->preview($assetIds, fn (int $id): ?string => $this->unusedAssetFinder->previewMutation($id)),
            fn (array $expected): array => $this->unusedAssetFinder->deleteAssets($assetIds, $expected),
        );
    }

    #[Route('/unused-assets/bulk-move', name: 'oronts_asset_pilot_unused_assets_bulk_move', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkMove(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $targetFolder = is_string($data['targetFolder'] ?? null) ? trim($data['targetFolder']) : '';

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        if ($targetFolder === '') {
            return new JsonResponse(['error' => 'targetFolder is required'], Response::HTTP_BAD_REQUEST);
        }

        return $this->executePlannedMutation(
            $data,
            $assetIds,
            'unused-move',
            ['targetFolder' => $targetFolder],
            'moved',
            fn (): array => $this->preview($assetIds, fn (int $id): ?string => $this->unusedAssetFinder->previewMutation($id, 'move')),
            fn (array $expected): array => $this->unusedAssetFinder->moveAssets($assetIds, $targetFolder, $expected),
        );
    }

    #[Route('/unused-assets/bulk-quarantine', name: 'oronts_asset_pilot_unused_assets_bulk_quarantine', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function bulkQuarantine(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $assetIds = $this->validatedBulkIds($data['assetIds'] ?? null, 'assetIds');
        if ($assetIds instanceof JsonResponse) {
            return $assetIds;
        }

        return $this->executePlannedMutation(
            $data,
            $assetIds,
            'unused-quarantine',
            [],
            'quarantined',
            fn (): array => $this->preview($assetIds, fn (int $id): ?string => $this->quarantineService->previewQuarantine($id)),
            fn (array $expected): array => $this->quarantineService->quarantine($assetIds, $expected),
        );
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, mixed> $requestData
     * @param callable(): array{failed: int, errors: array<int, string>} $preview
     * @param callable(array<string, string>): array<string, mixed> $apply
     */
    private function executePlannedMutation(
        array $data,
        array $assetIds,
        string $kind,
        array $requestData,
        string $counter,
        callable $preview,
        callable $apply,
    ): JsonResponse {
        $control = $this->mutationControl($data);
        if ($control instanceof JsonResponse) {
            return $control;
        }

        try {
            return $control['dryRun']
                ? $this->previewPlannedMutation($assetIds, $kind, $requestData, $counter, $preview)
                : $this->applyPlannedMutation($assetIds, $kind, $requestData, $control['token'], $apply);
        } catch (StaleApplyPlanException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to execute planned unused-asset mutation.', [
                'kind' => $kind,
                'exception' => $e,
            ]);

            return new JsonResponse(['error' => 'Failed to execute the unused-asset mutation.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @param array<string, mixed> $data @return array{dryRun: bool, token: string}|JsonResponse */
    private function mutationControl(array $data): array|JsonResponse
    {
        $dryRun = $data['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return new JsonResponse(['error' => 'dryRun must be a boolean.'], Response::HTTP_BAD_REQUEST);
        }

        $token = is_string($data['planToken'] ?? null) ? $data['planToken'] : '';
        if (!$dryRun && $token === '') {
            return new JsonResponse(['error' => 'A planToken from a fresh dry-run preview is required.'], Response::HTTP_BAD_REQUEST);
        }

        return ['dryRun' => $dryRun, 'token' => $token];
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, mixed> $requestData
     * @param callable(): array{failed: int, errors: array<int, string>} $preview
     */
    private function previewPlannedMutation(
        array $assetIds,
        string $kind,
        array $requestData,
        string $counter,
        callable $preview,
    ): JsonResponse {
        $this->mutationFingerprints->reset();
        $beforePreview = $this->mutationPlan($kind, $assetIds, $requestData);
        $result = $preview();
        $this->mutationFingerprints->reset();
        $plan = $this->mutationPlan($kind, $assetIds, $requestData);
        if ($beforePreview->config !== $plan->config || $beforePreview->targets != $plan->targets) {
            return new JsonResponse([
                'error' => 'An asset changed while the preview was being built. Preview again.',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->withPlanControl(
            [$counter => 0, ...$result, 'observerWarnings' => []],
            true,
            $this->applyPlans->issue($plan),
            count($assetIds),
        ));
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, mixed> $requestData
     * @param callable(array<string, string>): array<string, mixed> $apply
     */
    private function applyPlannedMutation(
        array $assetIds,
        string $kind,
        array $requestData,
        string $token,
        callable $apply,
    ): JsonResponse {
        $plan = $this->mutationPlan($kind, $assetIds, $requestData);
        $rejection = $this->mutationClaimRejection($this->applyPlans->claim($token, $plan));
        if ($rejection !== null) {
            return $rejection;
        }

        $this->mutationFingerprints->reset();
        $result = $apply($plan->fingerprintMap());

        return new JsonResponse($this->withPlanControl($result, false, null, count($assetIds)));
    }

    private function mutationClaimRejection(ApplyPlanStatus $status): ?JsonResponse
    {
        if ($status === ApplyPlanStatus::Malformed) {
            return new JsonResponse(['error' => 'The apply plan token is malformed.'], Response::HTTP_BAD_REQUEST);
        }

        return $status === ApplyPlanStatus::Claimed
            ? null
            : new JsonResponse(['error' => 'The apply plan is stale or was already used. Preview again.'], Response::HTTP_CONFLICT);
    }

    /** @param list<int> $assetIds @param array<string, mixed> $requestData */
    private function mutationPlan(string $kind, array $assetIds, array $requestData): ApplyPlan
    {
        return new ApplyPlan(
            kind: $kind,
            actor: $this->authorization->currentActor(),
            request: ['assetIds' => $assetIds, ...$requestData],
            config: $this->mutationFingerprints->planConfig(),
            targets: $this->mutationFingerprints->targets($assetIds),
        );
    }

    /** @param list<int> $assetIds @param callable(int): ?string $reason @return array{failed: int, errors: array<int, string>} */
    private function preview(array $assetIds, callable $reason): array
    {
        $errors = [];
        foreach ($assetIds as $assetId) {
            $error = $reason($assetId);
            if ($error !== null) {
                $errors[$assetId] = $error;
            }
        }

        return ['failed' => count($errors), 'errors' => $errors];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return array_filter([
            'type' => $request->query->get('type'),
            'extension' => $request->query->get('extension'),
            'before' => $request->query->get('before'),
            'after' => $request->query->get('after'),
            'folder' => $request->query->get('folder'),
            'confidence' => $request->query->get('confidence'),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $filters */
    private function validateFilters(array $filters): void
    {
        DateFilters::validate($filters);

        $confidence = $filters['confidence'] ?? null;
        if ($confidence !== null && !in_array($confidence, ConfidenceLevel::values(), true)) {
            throw new \InvalidArgumentException('Invalid confidence filter.');
        }
    }
}
