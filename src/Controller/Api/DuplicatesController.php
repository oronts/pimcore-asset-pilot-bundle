<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Controller\Api\Support\StreamsCsv;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetSearchServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DuplicatesController
{
    use DecodesJsonObject;
    use StreamsCsv;

    private const int MAX_LIMIT = 100;
    private const int EXPORT_PAGE = 200;
    private const int MAX_EXPORT_PAGES = 10000;

    public function __construct(
        protected readonly DuplicateDetectionService $duplicates,
        protected readonly DuplicateMergeService $merge,
        protected readonly AssetSearchServiceInterface $assets,
        protected readonly ApplyPlanServiceInterface $applyPlans,
        protected readonly ElementAuthorization $authorization,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Read-only report from the content-hash index. It never scans/hashes (that is the
     * asset-pilot:find-duplicates --scan command), so a request cannot block.
     */
    #[Route('/duplicates', name: 'oronts_asset_pilot_duplicates', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        [$page, $limit] = Pagination::fromRequest($request, self::MAX_LIMIT);
        $minCopies = max(2, $request->query->getInt('minCopies', 2));
        $typeParam = $request->query->get('type');
        $type = is_string($typeParam) && $typeParam !== '' ? $typeParam : null;

        try {
            $groups = $this->duplicates->findDuplicates($page, $limit, $minCopies, $type);
            $reps = $this->assets->summarize(array_values(array_filter(
                array_map(static fn ($group): ?int => $group->assetIds[0] ?? null, $groups),
            )));

            return new JsonResponse([
                'items' => array_map(static function ($group) use ($reps): array {
                    $repId = $group->assetIds[0] ?? null;
                    $rep = $repId !== null ? ($reps[$repId] ?? null) : null;

                    return [
                        'checksum' => $group->checksum,
                        'fileSize' => $group->fileSize,
                        'count' => $group->count,
                        'assetIds' => $group->assetIds,
                        'representative' => $rep === null ? null : [
                            'id' => (int) $rep['id'],
                            'filename' => $rep['filename'],
                            'fullPath' => $rep['full_path'],
                            'fileSize' => (int) $rep['file_size'],
                            'type' => $rep['type'],
                        ],
                    ];
                }, $groups),
                'total' => $this->duplicates->countDuplicateGroups($minCopies, $type),
                'page' => $page,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list duplicate assets.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to list duplicate assets.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Stream the whole duplicate report as CSV (checksum, copies, size, wasted bytes, asset ids), paged
     * lazily so the export stays memory-flat regardless of how many groups exist.
     */
    #[Route('/duplicates/export', name: 'oronts_asset_pilot_duplicates_export', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(Request $request): StreamedResponse
    {
        $minCopies = max(2, $request->query->getInt('minCopies', 2));
        $typeParam = $request->query->get('type');
        $type = is_string($typeParam) && $typeParam !== '' ? $typeParam : null;

        $rows = (function () use ($minCopies, $type): \Generator {
            $page = 1;
            do {
                $groups = $this->duplicates->findDuplicates($page, self::EXPORT_PAGE, $minCopies, $type);
                foreach ($groups as $group) {
                    yield [
                        $group->checksum,
                        $group->count,
                        $group->fileSize,
                        $group->fileSize * max(0, $group->count - 1),
                        implode(';', $group->assetIds),
                    ];
                }
            } while (count($groups) === self::EXPORT_PAGE && ++$page <= self::MAX_EXPORT_PAGES);
        })();

        return $this->streamCsv(
            'asset-pilot-duplicates-' . date('Y-m-d') . '.csv',
            ['Checksum', 'Copies', 'File Size (bytes)', 'Wasted (bytes)', 'Asset IDs'],
            $rows,
        );
    }

    /**
     * The selectable merge strategies (built-in plus any tagged custom ones) and the configured
     * default, so the UI can offer them without hard-coding the list.
     */
    #[Route('/duplicates/strategies', name: 'oronts_asset_pilot_duplicates_strategies', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function strategies(): JsonResponse
    {
        return new JsonResponse([
            'strategies' => $this->merge->availableStrategies(),
            'default' => $this->merge->defaultStrategyName(),
        ]);
    }

    /**
     * Consolidate one duplicate group (by content hash) onto a canonical asset and dispose of the
     * copies via the configured (or requested) strategy. Destructive, so Admin-only; pass
     * "dryRun": true to preview the plan without repointing or disposing anything.
     */
    #[Route('/duplicates/merge', name: 'oronts_asset_pilot_duplicates_merge', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function merge(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $runId = is_string($data['runId'] ?? null) ? $data['runId'] : '';
        if ($runId !== '') {
            return $this->resumeMerge($runId);
        }

        $checksum = is_string($data['checksum'] ?? null) ? $data['checksum'] : '';
        if ($checksum === '') {
            return new JsonResponse(['error' => 'A checksum is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $canonicalId = isset($data['canonicalId']) ? (int) $data['canonicalId'] : null;
        $strategy = is_string($data['strategy'] ?? null) ? $data['strategy'] : null;
        $dryRun = ($data['dryRun'] ?? false) === true;

        $group = $this->duplicates->groupForChecksum($checksum);
        if ($group === null) {
            return new JsonResponse(['error' => 'No duplicate group with at least two live assets for that checksum.'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            return $this->mergeGroup($data, $group, $checksum, $canonicalId, $strategy, $dryRun);
        } catch (StaleApplyPlanException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (NotPermittedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to merge duplicates.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to merge duplicates.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @param array<string, mixed> $data */
    private function mergeGroup(
        array $data,
        DuplicateGroup $group,
        string $checksum,
        ?int $canonicalId,
        ?string $strategy,
        bool $dryRun,
    ): JsonResponse {
        $canonicalId ??= min($group->assetIds);
        $resolvedStrategy = $strategy ?? $this->merge->defaultStrategyName();
        $targets = $this->merge->planTargets($group);
        $plan = $this->duplicateMergePlan($checksum, $canonicalId, $resolvedStrategy, $targets);
        $execution = $this->mergeExecution($data, $plan, $dryRun);
        if ($execution instanceof JsonResponse) {
            return $execution;
        }

        $outcome = $this->merge->merge(
            $group,
            $canonicalId,
            $resolvedStrategy,
            $dryRun,
            $execution['fingerprints'],
        );

        return $this->mergeResponse($outcome, $dryRun, $execution['planToken'], $outcome->runId);
    }

    /** @param list<\Oronts\AssetPilotBundle\Model\ApplyPlanTarget> $targets */
    private function duplicateMergePlan(string $checksum, int $canonicalId, string $strategy, array $targets): ApplyPlan
    {
        return new ApplyPlan(
            kind: 'duplicate-merge',
            actor: $this->authorization->currentActor(),
            request: ['checksum' => $checksum, 'canonicalId' => $canonicalId, 'strategy' => $strategy],
            config: ['defaultStrategy' => $this->merge->defaultStrategyName()],
            targets: $targets,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{planToken: ?string, fingerprints: array<string, string>|null}|JsonResponse
     */
    private function mergeExecution(array $data, ApplyPlan $plan, bool $dryRun): array|JsonResponse
    {
        if ($dryRun) {
            return ['planToken' => $this->applyPlans->issue($plan), 'fingerprints' => null];
        }

        $token = is_string($data['planToken'] ?? null) ? $data['planToken'] : '';
        if ($token === '') {
            return new JsonResponse(['error' => 'A planToken from a fresh preview is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $rejection = $this->mergeClaimRejection($this->applyPlans->claim($token, $plan));
        if ($rejection !== null) {
            return $rejection;
        }

        return ['planToken' => null, 'fingerprints' => $this->planFingerprints($plan)];
    }

    private function mergeClaimRejection(ApplyPlanStatus $status): ?JsonResponse
    {
        if ($status === ApplyPlanStatus::Malformed) {
            return new JsonResponse(['error' => 'The apply plan token is malformed.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $status === ApplyPlanStatus::Claimed
            ? null
            : new JsonResponse(['error' => 'The apply plan is stale or was already used. Preview again.'], JsonResponse::HTTP_CONFLICT);
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

    private function mergeResponse(MergeOutcome $outcome, bool $dryRun, ?string $planToken, ?string $statusRunId): JsonResponse
    {
        return new JsonResponse([
            'checksum' => $outcome->checksum,
            'canonicalId' => $outcome->canonicalId,
            'dryRun' => $dryRun,
            'planToken' => $planToken,
            'runId' => $outcome->runId,
            'status' => $outcome->status?->value,
            'statusUrl' => $statusRunId === null ? null : $this->urlGenerator->generate(
                'oronts_asset_pilot_operation_run_get',
                ['id' => $statusRunId],
            ),
            'dispositions' => array_map(static fn ($disposition): array => [
                'copyId' => $disposition->copyId,
                'outcome' => $disposition->outcome->value,
                'reason' => $disposition->reason,
            ], $outcome->dispositions),
        ]);
    }

    private function resumeMerge(string $runId): JsonResponse
    {
        try {
            $outcome = $this->merge->resume($runId);

            return $this->mergeResponse($outcome, false, null, $runId);
        } catch (NotPermittedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resume duplicate merge.', ['runId' => $runId, 'exception' => $e]);

            return new JsonResponse(['error' => 'Failed to resume duplicate merge.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
