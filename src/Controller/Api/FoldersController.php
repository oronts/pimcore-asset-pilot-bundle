<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Controller\Api\Support\RejectsClaimedPlan;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepServiceInterface;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class FoldersController
{
    use DecodesJsonObject;
    use RejectsClaimedPlan;

    private const int MAX_DELETE = 200;

    public function __construct(
        protected readonly EmptyFolderSweepServiceInterface $sweep,
        protected readonly ApplyPlanServiceInterface $applyPlans,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/folders/empty', name: 'oronts_asset_pilot_folders_empty', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function empty(Request $request): JsonResponse
    {
        [$page, $limit] = Pagination::fromRequest($request, self::MAX_DELETE);
        $folder = $request->query->get('folder');

        try {
            return new JsonResponse($this->sweep->findEmpty($folder !== null && $folder !== '' ? (string) $folder : null, $page, $limit));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list empty folders.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to list empty folders.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/folders/empty/delete', name: 'oronts_asset_pilot_folders_empty_delete', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function deleteEmpty(Request $request): JsonResponse
    {
        $input = $this->deleteInput($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            return $input['dryRun']
                ? $this->previewDelete($input['ids'])
                : $this->applyDelete($input['ids'], $input['planToken']);
        } catch (StaleApplyPlanException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete empty folders.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to delete empty folders.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array{ids: list<int>, dryRun: bool, planToken: string}|JsonResponse */
    private function deleteInput(Request $request): array|JsonResponse
    {
        $body = $this->decodeJsonObject($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $ids = BulkIds::clean($body['ids'] ?? null);
        if ($ids === []) {
            return new JsonResponse(['error' => 'ids must list one or more positive folder ids.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (count($ids) > self::MAX_DELETE) {
            return new JsonResponse(['error' => sprintf('Too many ids; delete at most %d at once.', self::MAX_DELETE)], JsonResponse::HTTP_BAD_REQUEST);
        }

        $dryRun = $body['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return new JsonResponse(['error' => 'dryRun must be a boolean.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $planToken = is_string($body['planToken'] ?? null) ? $body['planToken'] : '';
        if (!$dryRun && $planToken === '') {
            return new JsonResponse(['error' => 'A planToken from a fresh dry-run preview is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return ['ids' => $ids, 'dryRun' => $dryRun, 'planToken' => $planToken];
    }

    /** @param list<int> $folderIds */
    private function previewDelete(array $folderIds): JsonResponse
    {
        $before = $this->sweep->createDeletePlan($folderIds);
        $result = $this->sweep->previewDelete($folderIds);
        $plan = $this->sweep->createDeletePlan($folderIds);
        if ($before->config !== $plan->config || $before->targets != $plan->targets) {
            return new JsonResponse(['error' => 'A folder changed while the preview was being built. Preview again.'], JsonResponse::HTTP_CONFLICT);
        }

        return new JsonResponse([
            ...$result,
            'dryRun' => true,
            'planToken' => $this->applyPlans->issue($plan),
        ]);
    }

    /** @param list<int> $folderIds */
    private function applyDelete(array $folderIds, string $planToken): JsonResponse
    {
        $plan = $this->sweep->createDeletePlan($folderIds);
        $rejection = $this->rejectClaimedPlan($this->applyPlans->claim($planToken, $plan));
        if ($rejection !== null) {
            return $rejection;
        }

        return new JsonResponse([
            ...$this->sweep->deleteEmpty($folderIds, $plan->fingerprintMap()),
            'dryRun' => false,
            'planToken' => null,
        ]);
    }

}
