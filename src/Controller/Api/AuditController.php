<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AuditController
{
    public function __construct(
        protected readonly AuditLogger $auditLogger,
        protected readonly LoggerInterface $logger,
        protected readonly LoopGuard $loopGuard,
    ) {}

    #[Route('/audit', name: 'oronts_asset_pilot_audit', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $filters = array_filter([
            'object_class' => $request->query->get('class'),
            'status' => $request->query->get('status'),
            'rule_name' => $request->query->get('ruleName'),
        ]);

        $this->logger->debug('Asset Pilot API: audit list requested', [
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters,
        ]);

        $result = $this->auditLogger->getPaginated($page, $limit, $filters);

        return new JsonResponse($result);
    }

    #[Route('/audit/stats', name: 'oronts_asset_pilot_audit_stats', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function stats(): JsonResponse
    {
        return new JsonResponse($this->auditLogger->getStats());
    }

    #[Route('/audit/export', name: 'oronts_asset_pilot_audit_export', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(Request $request): StreamedResponse
    {
        $filters = array_filter([
            'object_class' => $request->query->get('class'),
            'status' => $request->query->get('status'),
            'rule_name' => $request->query->get('ruleName'),
        ]);

        $items = $this->auditLogger->getRecent(10000, $filters);

        return new StreamedResponse(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Asset ID', 'From', 'To', 'Object ID', 'Class', 'Rule', 'Trigger', 'Status', 'Duration (ms)', 'Error', 'Date']);

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item['id'] ?? '',
                    $item['asset_id'] ?? '',
                    $item['asset_path_from'] ?? '',
                    $item['asset_path_to'] ?? '',
                    $item['object_id'] ?? '',
                    $item['object_class'] ?? '',
                    $item['rule_name'] ?? '',
                    $item['trigger_type'] ?? '',
                    $item['status'] ?? '',
                    $item['duration_ms'] ?? '',
                    $item['error_message'] ?? '',
                    $item['created_at'] ?? '',
                ]);
            }

            fclose($handle);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="asset-pilot-audit-' . date('Y-m-d') . '.csv"',
        ]);
    }

    #[Route('/audit/by-rule/{ruleName}/assets', name: 'oronts_asset_pilot_audit_rule_assets', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function assetsByRule(string $ruleName, Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));

        $filters = array_filter([
            'since' => $request->query->get('since'),
            'object_class' => $request->query->get('class'),
        ]);

        $result = $this->auditLogger->getDistinctAssetsByRule($ruleName, $page, $limit, $filters);

        return new JsonResponse($result);
    }

    #[Route('/audit/{id}/revert', name: 'oronts_asset_pilot_audit_revert', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function revert(int $id): JsonResponse
    {
        $entry = $this->auditLogger->findById($id);
        if ($entry === null) {
            return new JsonResponse(['error' => 'Audit entry not found'], Response::HTTP_NOT_FOUND);
        }

        if (($entry['status'] ?? '') !== OperationStatus::Completed->value) {
            return new JsonResponse(['error' => 'Only completed operations can be reverted'], Response::HTTP_BAD_REQUEST);
        }

        $assetId = (int) ($entry['asset_id'] ?? 0);
        $asset = Asset::getById($assetId);
        if ($asset === null) {
            return new JsonResponse(['error' => 'Asset not found'], Response::HTTP_NOT_FOUND);
        }

        $currentPath = $asset->getRealFullPath();
        $targetPath = $entry['asset_path_to'] ?? '';

        if ($currentPath !== $targetPath) {
            return new JsonResponse([
                'error' => 'Asset has been moved since this operation. Current path does not match.',
                'currentPath' => $currentPath,
                'expectedPath' => $targetPath,
            ], Response::HTTP_CONFLICT);
        }

        $sourcePath = $entry['asset_path_from'] ?? '';
        $sourceDir = dirname($sourcePath);
        $sourceFilename = basename($sourcePath);

        try {
            $folder = Asset\Service::createFolderByPath($sourceDir);
            $asset->setParent($folder);
            $asset->setFilename($sourceFilename);
            $this->saveReverted($asset, $assetId);

            // Log the revert as a new audit entry
            $revertOperation = new MoveOperation(
                assetId: $assetId,
                sourcePath: $targetPath,
                targetPath: $sourcePath,
                objectId: (int) ($entry['object_id'] ?? 0),
                objectClass: $entry['object_class'] ?? '',
                ruleName: 'revert:' . ($entry['rule_name'] ?? ''),
                status: OperationStatus::Completed,
                triggerType: TriggerType::Manual,
            );
            $this->auditLogger->log($revertOperation);

            $this->logger->info('Asset Pilot: reverted audit entry {id}, asset {assetId} moved back to {path}', [
                'id' => $id,
                'assetId' => $assetId,
                'path' => $sourcePath,
            ]);

            return new JsonResponse(['message' => 'Operation reverted successfully', 'newPath' => $sourcePath]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to revert audit entry {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to revert: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Persist a reverted asset without re-triggering the organize pipeline. The save fires
     * pimcore.asset.postUpdate, which AssetUploadListener would otherwise pick up and move the
     * asset back to the rule target. Mark recently-moved before releasing the processing guard so
     * the listener stays guarded across the whole window. (P0-3, P2-21)
     */
    protected function saveReverted(Asset $asset, int $assetId): void
    {
        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $asset->save();
            $this->loopGuard->markAssetRecentlyMoved($assetId);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
        }
    }
}
