<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\RevertFailure;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\RevertException;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\RevertResult;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
use Pimcore\Model\Asset;
use Pimcore\Tool\Admin;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Programmatic, loop-safe revert of a completed move: moves the asset back to its source path,
 * records a `revert:` audit entry, and fires REVERTED. Re-verifies state before acting (entry is
 * completed, asset still at the moved-to path, per-asset workspace ACL) and throws a typed
 * RevertException otherwise, so any caller — the REST controller, a command, or consumer code — can
 * revert without going through HTTP.
 */
class OperationReverter
{
    public function __construct(
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly LoopGuard $loopGuard,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws RevertException when the operation cannot be reverted
     */
    public function revertById(int $auditId): RevertResult
    {
        $entry = $this->auditLogger->findById($auditId);
        if ($entry === null) {
            throw RevertException::of(RevertFailure::AuditEntryNotFound, 'Audit entry not found');
        }

        if (($entry['status'] ?? '') !== OperationStatus::Completed->value) {
            throw RevertException::of(RevertFailure::NotCompleted, 'Only completed operations can be reverted');
        }

        $assetId = (int) ($entry['asset_id'] ?? 0);
        $asset = $this->loadAsset($assetId);
        if ($asset === null) {
            throw RevertException::of(RevertFailure::AssetNotFound, 'Asset not found');
        }

        // Per-asset Pimcore workspace ACL on top of the admin-level operate permission.
        if (!$asset->isAllowed('publish')) {
            throw RevertException::of(RevertFailure::PermissionDenied, 'You are not permitted to revert this asset');
        }

        $currentPath = $asset->getRealFullPath();
        $targetPath = (string) ($entry['asset_path_to'] ?? '');
        if ($currentPath !== $targetPath) {
            throw RevertException::of(RevertFailure::PathConflict, 'Asset has been moved since this operation. Current path does not match.', [
                'currentPath' => $currentPath,
                'expectedPath' => $targetPath,
            ]);
        }

        $sourcePath = (string) ($entry['asset_path_from'] ?? '');
        $sourceDir = \dirname($sourcePath);
        $sourceFilename = basename($sourcePath);

        // Authorize before recreating the original folder tree (createFolderByPath is a side effect):
        // check the create ACL on the nearest existing ancestor of the restore target.
        $targetParent = $this->nearestExistingFolder($sourceDir);
        if ($targetParent !== null && !$targetParent->isAllowed('create')) {
            throw RevertException::of(RevertFailure::PermissionDenied, 'You are not permitted to restore this asset to its original folder');
        }

        try {
            // createFolderByPath runs before the LoopGuard window, which is safe: it creates Asset\Folder
            // elements, not DataObjects, so it cannot re-enter the organize pipeline (the save listener
            // is on DataObjects). Only the asset move below needs the guard, via saveReverted().
            $folder = $this->createFolder($sourceDir);
            $asset->setParent($folder);
            $asset->setFilename($sourceFilename);
            $this->saveReverted($asset, $assetId);

            // Record who performed the revert: a revert is a deliberate human action, unlike the
            // rule-driven moves whose actor is the rule (so they stay null). The audit write and event
            // stay inside the guard window so a failure here is reported like any other revert failure.
            $this->auditLogger->log(new MoveOperation(
                assetId: $assetId,
                sourcePath: $targetPath,
                targetPath: $sourcePath,
                objectId: (int) ($entry['object_id'] ?? 0),
                objectClass: (string) ($entry['object_class'] ?? ''),
                ruleName: 'revert:' . ($entry['rule_name'] ?? ''),
                status: OperationStatus::Completed,
                triggerType: TriggerType::Manual,
                userId: $this->currentUserId(),
            ));

            $this->eventDispatcher->dispatch(
                new AssetMutationEvent([$assetId], 'revert', ['from' => $targetPath, 'to' => $sourcePath]),
                AssetPilotEvents::REVERTED,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to revert audit entry {id}: {error}', [
                'id' => $auditId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw RevertException::of(RevertFailure::ExecutionFailed, 'Failed to revert the operation.', [], $e);
        }

        $this->logger->info('Asset Pilot: reverted audit entry {id}, asset {assetId} moved back to {path}', [
            'id' => $auditId,
            'assetId' => $assetId,
            'path' => $sourcePath,
        ]);

        return new RevertResult($assetId, $targetPath, $sourcePath);
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    protected function nearestExistingFolder(string $path): ?Asset\Folder
    {
        return AssetFolders::nearestExisting($path);
    }

    protected function createFolder(string $path): Asset\Folder
    {
        return Asset\Service::createFolderByPath($path);
    }

    protected function currentUserId(): ?int
    {
        return Admin::getCurrentUser()?->getId();
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
