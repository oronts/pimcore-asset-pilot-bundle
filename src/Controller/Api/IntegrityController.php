<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetIntegrityServiceInterface;
use Oronts\AssetPilotBundle\Service\IntegrityHealFingerprintService;
use Oronts\AssetPilotBundle\Service\IntegrityHealHistoryServiceInterface;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealerInterface;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class IntegrityController
{
    use DecodesJsonObject;

    /**
     * Each checked/healed asset is loaded and render-tested (and a heal probes its versions), so the
     * per-request work is hard-capped: both the scan page and an explicit id list are bounded to
     * MAX_ITEMS. A whole-catalog sweep belongs on the CLI / async, never a single request.
     */
    private const int MAX_ITEMS = 50;

    public function __construct(
        protected readonly AssetIntegrityServiceInterface $integrity,
        protected readonly VersionRollbackHealerInterface $healer,
        protected readonly IntegrityHealHistoryServiceInterface $history,
        protected readonly LoggerInterface $logger,
        protected readonly ApplyPlanServiceInterface $applyPlans,
        protected readonly IntegrityHealFingerprintService $healFingerprints,
        protected readonly ElementAuthorizationInterface $authorization,
    ) {}

    #[Route('/integrity', name: 'oronts_asset_pilot_integrity', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function brokenAssets(Request $request): JsonResponse
    {
        if ($request->query->has('ids')) {
            $parsed = BulkIds::fromCsv((string) $request->query->get('ids'));
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

        [$page, $limit] = Pagination::fromRequest($request, self::MAX_ITEMS, 25);

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
        $input = $this->healInput($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            return $this->executeHeal($input['ids'], $input['dryRun'], $input['planToken']);
        } catch (StaleApplyPlanException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to heal assets.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to heal assets.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array{ids: list<int>, dryRun: bool, planToken: string}|JsonResponse */
    private function healInput(Request $request): array|JsonResponse
    {
        $body = $this->decodeJsonObject($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $ids = BulkIds::clean($body['ids'] ?? null);
        if ($ids === []) {
            return new JsonResponse(['error' => 'ids must list one or more positive asset ids.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (count($ids) > self::MAX_ITEMS) {
            return new JsonResponse(['error' => sprintf('Too many ids; heal at most %d at once.', self::MAX_ITEMS)], JsonResponse::HTTP_BAD_REQUEST);
        }

        $dryRun = $body['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return new JsonResponse(['error' => 'dryRun must be a boolean.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        sort($ids, SORT_NUMERIC);

        $planToken = is_string($body['planToken'] ?? null) ? $body['planToken'] : '';
        if (!$dryRun && $planToken === '') {
            return new JsonResponse(['error' => 'A planToken from a fresh dry-run preview is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return ['ids' => $ids, 'dryRun' => $dryRun, 'planToken' => $planToken];
    }

    /** @param list<int> $assetIds */
    private function executeHeal(array $assetIds, bool $dryRun, string $planToken): JsonResponse
    {
        [$plan, $previewResults] = $this->previewPlan($assetIds);
        if ($plan === null) {
            return new JsonResponse([
                'error' => 'An asset changed while the integrity preview was being built. Preview again.',
            ], JsonResponse::HTTP_CONFLICT);
        }

        if ($dryRun) {
            return new JsonResponse([
                'dryRun' => true,
                'planToken' => $this->applyPlans->issue($plan),
                'results' => $this->serializeResults($assetIds, $previewResults),
            ]);
        }

        $rejection = $this->healPlanRejection($this->applyPlans->claim($planToken, $plan));
        if ($rejection !== null) {
            return $rejection;
        }

        $results = $this->healer->healPlannedBatch($assetIds, $this->planFingerprints($plan));

        return new JsonResponse([
            'dryRun' => false,
            'planToken' => null,
            'results' => $this->serializeResults($assetIds, $results),
        ]);
    }

    private function healPlanRejection(ApplyPlanStatus $status): ?JsonResponse
    {
        if ($status === ApplyPlanStatus::Malformed) {
            return new JsonResponse(['error' => 'The apply plan token is malformed.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $status === ApplyPlanStatus::Claimed
            ? null
            : new JsonResponse([
                'error' => 'The apply plan is stale or was already used. Preview again.',
            ], JsonResponse::HTTP_CONFLICT);
    }

    /** @return array<string, string> */
    private function planFingerprints(ApplyPlan $plan): array
    {
        $fingerprints = [];
        foreach ($plan->targets as $target) {
            $fingerprints[$target->id] = $target->fingerprint;
        }

        return $fingerprints;
    }

    /**
     * @param list<int> $assetIds
     * @return array{ApplyPlan|null, array<int, HealResult>}
     */
    private function previewPlan(array $assetIds): array
    {
        $before = $this->healFingerprints->fingerprintMap($assetIds);
        $results = [];
        foreach ($assetIds as $assetId) {
            $results[$assetId] = $this->healer->previewById($assetId);
        }
        $after = $this->healFingerprints->fingerprintMap($assetIds);

        if ($before !== $after) {
            return [null, $results];
        }

        return [new ApplyPlan(
            kind: 'integrity-heal',
            actor: $this->authorization->currentActor(),
            request: ['assetIds' => $assetIds],
            config: $this->healFingerprints->planConfig(),
            targets: $this->healFingerprints->targets($assetIds, $after, $results),
        ), $results];
    }

    /**
     * @param list<int> $assetIds
     * @param array<int, HealResult> $results
     * @return list<array<string, mixed>>
     */
    private function serializeResults(array $assetIds, array $results): array
    {
        return array_map(static function (int $assetId) use ($results): array {
            $result = $results[$assetId];

            return [
                'assetId' => $assetId,
                'outcome' => $result->outcome->value,
                'toVersion' => $result->toVersion,
                'checker' => $result->checker,
                'reason' => $result->reason,
                'observerWarnings' => $result->observerWarnings,
            ];
        }, $assetIds);
    }

    #[Route('/integrity/history', name: 'oronts_asset_pilot_integrity_history', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function history(Request $request): JsonResponse
    {
        [$page, $limit] = Pagination::fromRequest($request, self::MAX_ITEMS, 25);

        try {
            return new JsonResponse($this->history->getPaginated($page, $limit));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read integrity heal history.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to read integrity heal history.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/integrity/undo', name: 'oronts_asset_pilot_integrity_undo', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function undo(Request $request): JsonResponse
    {
        $body = $this->decodeJsonObject($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $rawId = $body['assetId'] ?? null;
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
