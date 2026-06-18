<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\BulkIds;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class IntegrityController
{
    /**
     * Each checked/healed asset is loaded and render-tested (and a heal probes its versions), so the
     * per-request work is hard-capped: both the scan page and an explicit id list are bounded to
     * MAX_ITEMS. A whole-catalog sweep belongs on the CLI / async, never a single request.
     */
    private const int MAX_ITEMS = 50;

    public function __construct(
        protected readonly AssetIntegrityService $integrity,
        protected readonly VersionRollbackHealer $healer,
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

    #[Route('/integrity/heal', name: 'oronts_asset_pilot_integrity_heal', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function heal(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Request body must be a JSON object.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $ids = BulkIds::clean($body['ids'] ?? null);
        if ($ids === []) {
            return new JsonResponse(['error' => 'ids must list one or more positive asset ids.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (count($ids) > self::MAX_ITEMS) {
            return new JsonResponse(['error' => sprintf('Too many ids; heal at most %d at once.', self::MAX_ITEMS)], JsonResponse::HTTP_BAD_REQUEST);
        }

        $dryRun = (bool) ($body['dryRun'] ?? false);

        try {
            $results = [];
            foreach ($ids as $id) {
                $result = $this->healer->healById($id, $dryRun);
                $results[] = [
                    'assetId' => $id,
                    'outcome' => $result->outcome->value,
                    'toVersion' => $result->toVersion,
                    'checker' => $result->checker,
                    'reason' => $result->reason,
                ];
            }

            return new JsonResponse(['dryRun' => $dryRun, 'results' => $results]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to heal assets.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to heal assets.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/integrity/undo', name: 'oronts_asset_pilot_integrity_undo', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function undo(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $rawId = is_array($body) ? ($body['assetId'] ?? null) : null;
        $assetId = (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) ? (int) $rawId : 0;
        if ($assetId <= 0) {
            return new JsonResponse(['error' => 'assetId must be a positive integer.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            if (!$this->healer->undo($assetId)) {
                return new JsonResponse(['error' => 'No reversible heal found for this asset.'], JsonResponse::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['assetId' => $assetId, 'undone' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to undo asset heal.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to undo asset heal.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
