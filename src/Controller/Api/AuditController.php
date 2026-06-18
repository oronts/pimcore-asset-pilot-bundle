<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
use Pimcore\Model\Asset;
use Pimcore\Tool\Admin;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AuditController
{
    public function __construct(
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly LoggerInterface $logger,
        protected readonly LoopGuard $loopGuard,
        protected readonly EventDispatcherInterface $eventDispatcher,
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

        $result = $this->auditLogger->getPaginated(
            $page,
            $limit,
            $filters,
            $request->query->get('sort'),
            $request->query->get('order'),
        );

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
                fputcsv($handle, array_map($this->sanitizeCsvCell(...), [
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
                ]));
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

        // Per-asset Pimcore workspace ACL on top of the admin-level operate permission.
        if (!$asset->isAllowed('publish')) {
            return new JsonResponse(['error' => 'You are not permitted to revert this asset'], Response::HTTP_FORBIDDEN);
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

        // Authorize before recreating the original folder tree (createFolderByPath is a side effect):
        // check the create ACL on the nearest existing ancestor of the restore target.
        $targetParent = AssetFolders::nearestExisting($sourceDir);
        if ($targetParent !== null && !$targetParent->isAllowed('create')) {
            return new JsonResponse(['error' => 'You are not permitted to restore this asset to its original folder'], Response::HTTP_FORBIDDEN);
        }

        try {
            $folder = Asset\Service::createFolderByPath($sourceDir);
            $asset->setParent($folder);
            $asset->setFilename($sourceFilename);
            $this->saveReverted($asset, $assetId);

            // Record who performed the revert: a revert is a deliberate human action, unlike the
            // rule-driven moves whose actor is the rule (so they stay null).
            $revertOperation = new MoveOperation(
                assetId: $assetId,
                sourcePath: $targetPath,
                targetPath: $sourcePath,
                objectId: (int) ($entry['object_id'] ?? 0),
                objectClass: $entry['object_class'] ?? '',
                ruleName: 'revert:' . ($entry['rule_name'] ?? ''),
                status: OperationStatus::Completed,
                triggerType: TriggerType::Manual,
                userId: Admin::getCurrentUser()?->getId(),
            );
            $this->auditLogger->log($revertOperation);

            $this->logger->info('Asset Pilot: reverted audit entry {id}, asset {assetId} moved back to {path}', [
                'id' => $id,
                'assetId' => $assetId,
                'path' => $sourcePath,
            ]);

            $this->eventDispatcher->dispatch(
                new AssetMutationEvent([$assetId], 'revert', ['from' => $targetPath, 'to' => $sourcePath]),
                AssetPilotEvents::REVERTED,
            );

            return new JsonResponse(['message' => 'Operation reverted successfully', 'newPath' => $sourcePath]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to revert audit entry {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new JsonResponse(['error' => 'Failed to revert the operation.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // A cell starting with = + - @ (or a control char) is executed as a formula by Excel/Sheets;
    // prefix it with a quote to neutralize CSV formula injection.
    protected function sanitizeCsvCell(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    // The save fires asset.postUpdate -> AssetUploadListener, which would re-organize the asset back.
    // Mark recently-moved before releasing the processing guard so the listener stays guarded throughout.
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
