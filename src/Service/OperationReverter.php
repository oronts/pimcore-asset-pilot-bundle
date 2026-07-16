<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\RevertFailure;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;
use Oronts\AssetPilotBundle\Exception\RevertException;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\RevertResult;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OperationReverter
{
    public function __construct(
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly OperationJournalInterface $operationJournal,
        protected readonly LoopGuard $loopGuard,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoggerInterface $logger,
        protected readonly ElementAuthorization $authorization,
    ) {}

    /**
     * @throws RevertException when the operation cannot be reverted
     */
    public function revertById(int $auditId): RevertResult
    {
        $entry = $this->completedEntry($auditId);
        $assetId = (int) ($entry['asset_id'] ?? 0);
        if (!$this->loopGuard->acquireAsset($assetId)) {
            throw RevertException::of(RevertFailure::PathConflict, 'Asset is being processed by another job');
        }

        try {
            return $this->revertLocked($entry, $auditId, $assetId);
        } finally {
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    /** @return array<string, mixed> */
    private function completedEntry(int $auditId): array
    {
        $entry = $this->auditLogger->findById($auditId);
        if ($entry === null) {
            throw RevertException::of(RevertFailure::AuditEntryNotFound, 'Audit entry not found');
        }
        if (!in_array(($entry['status'] ?? ''), [OperationStatus::Completed->value, OperationStatus::CompletedWithObserverError->value], true)) {
            throw RevertException::of(RevertFailure::NotCompleted, 'Only completed operations can be reverted');
        }

        return $entry;
    }

    /** @param array<string, mixed> $entry */
    private function revertLocked(array $entry, int $auditId, int $assetId): RevertResult
    {
        $asset = $this->loadAsset($assetId);
        if ($asset === null) {
            throw RevertException::of(RevertFailure::AssetNotFound, 'Asset not found');
        }

        $targetPath = (string) ($entry['asset_path_to'] ?? '');
        $sourcePath = (string) ($entry['asset_path_from'] ?? '');
        $this->assertRevertAllowed($asset, $targetPath);
        if (!$this->loopGuard->acquireTarget($sourcePath)) {
            throw RevertException::of(RevertFailure::PathConflict, 'The original path is being allocated by another job');
        }

        try {
            $this->assertSourceAvailable($assetId, $sourcePath);
            $this->assertTargetFolderAllowed(\dirname($sourcePath));

            return $this->executeRevert($entry, $auditId, $asset, $assetId, $targetPath, $sourcePath);
        } finally {
            $this->loopGuard->releaseTarget($sourcePath);
        }
    }

    private function assertRevertAllowed(Asset $asset, string $expectedPath): void
    {
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            throw RevertException::of(RevertFailure::PermissionDenied, 'You are not permitted to revert this asset');
        }

        $currentPath = $asset->getRealFullPath();
        if ($currentPath !== $expectedPath) {
            throw RevertException::of(RevertFailure::PathConflict, 'Asset has been moved since this operation. Current path does not match.', [
                'currentPath' => $currentPath,
                'expectedPath' => $expectedPath,
            ]);
        }
    }

    private function assertSourceAvailable(int $assetId, string $sourcePath): void
    {
        $occupant = $this->assetAtPath($sourcePath);
        if ($occupant !== null && (int) $occupant->getId() !== $assetId) {
            throw RevertException::of(RevertFailure::PathConflict, 'The original asset path is occupied');
        }
    }

    private function assertTargetFolderAllowed(string $sourceDir): void
    {
        $targetParent = $this->nearestExistingFolder($sourceDir);
        if ($targetParent !== null && !$this->authorization->isAllowed($targetParent, 'create')) {
            throw RevertException::of(RevertFailure::PermissionDenied, 'You are not permitted to restore this asset to its original folder');
        }
    }

    /** @param array<string, mixed> $entry */
    private function executeRevert(array $entry, int $auditId, Asset $asset, int $assetId, string $targetPath, string $sourcePath): RevertResult
    {
        $operation = $this->operationJournal->begin($this->intent(
            $entry,
            $auditId,
            $assetId,
            $targetPath,
            $sourcePath,
        ));

        try {
            $asset->setParent($this->createFolder(\dirname($sourcePath)));
            $asset->setFilename(basename($sourcePath));
            $this->loopGuard->refreshTarget($sourcePath);
            $this->saveReverted($asset, $assetId);
        } catch (\Throwable $e) {
            return $this->finalizeRevert($operation, $auditId, $assetId, $targetPath, $sourcePath, $e);
        }

        return $this->finalizeRevert($operation, $auditId, $assetId, $targetPath, $sourcePath);
    }

    /** @param array<string, mixed> $entry */
    private function intent(
        array $entry,
        int $parentAuditId,
        int $assetId,
        string $sourcePath,
        string $targetPath,
    ): OperationIntent {
        return new OperationIntent(
            OperationKind::Revert,
            $assetId,
            $sourcePath,
            $targetPath,
            (int) ($entry['object_id'] ?? 0),
            (string) ($entry['object_class'] ?? ''),
            'revert:' . ($entry['rule_name'] ?? ''),
            TriggerType::Manual,
            $this->authorization->currentActor(),
            ['revertedAuditId' => $parentAuditId],
            $parentAuditId,
        );
    }

    private function finalizeRevert(
        OperationHandle $operation,
        int $parentAuditId,
        int $assetId,
        string $sourcePath,
        string $targetPath,
        ?\Throwable $cause = null,
    ): RevertResult {
        $status = $this->classifyPersistedRevert($assetId, $sourcePath, $targetPath);
        if ($status === OperationStatus::RecoveryRequired) {
            $this->throwRecoveryRequired($operation, $assetId, $cause);
        }
        if ($status === OperationStatus::Failed) {
            $this->throwRevertFailed($operation, $parentAuditId, $assetId, $cause);
        }

        return $this->completeRevert($operation, $parentAuditId, $assetId, $sourcePath, $targetPath);
    }

    private function throwRecoveryRequired(OperationHandle $operation, int $assetId, ?\Throwable $cause): never
    {
        $message = 'The persisted revert state is uncertain and requires recovery.';
        try {
            $this->operationJournal->complete($operation, OperationStatus::RecoveryRequired, $message);
        } catch (\Throwable $journalError) {
            $this->logger->critical('Asset Pilot: uncertain revert could not be marked for recovery.', [
                'operation_id' => $operation->operationId,
                'asset_id' => $assetId,
                'exception' => $journalError,
            ]);
        }

        throw RevertException::of(RevertFailure::RecoveryRequired, $message, ['operationId' => $operation->operationId], $cause);
    }

    private function throwRevertFailed(OperationHandle $operation, int $parentAuditId, int $assetId, ?\Throwable $cause): never
    {
        $this->operationJournal->complete($operation, OperationStatus::Failed, 'Revert failed.');
        $this->logger->error('Asset Pilot: failed to revert audit entry {id}.', [
            'id' => $parentAuditId,
            'asset_id' => $assetId,
            'exception' => $cause,
        ]);

        throw RevertException::of(RevertFailure::ExecutionFailed, 'Failed to revert the operation.', [], $cause);
    }

    private function completeRevert(OperationHandle $operation, int $parentAuditId, int $assetId, string $sourcePath, string $targetPath): RevertResult
    {
        $observerErrors = NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new AssetMutationEvent([$assetId], 'revert', ['from' => $sourcePath, 'to' => $targetPath]),
            AssetPilotEvents::REVERTED,
            $this->logger,
            ['audit_id' => $parentAuditId, 'asset_id' => $assetId],
        );
        $completedStatus = $observerErrors === [] ? OperationStatus::Completed : OperationStatus::CompletedWithObserverError;
        try {
            $this->operationJournal->complete(
                $operation,
                $completedStatus,
                $observerErrors === [] ? null : 'Observer delivery failed.',
            );
        } catch (\Throwable $journalError) {
            $this->logger->critical('Asset Pilot: committed revert requires journal recovery.', [
                'operation_id' => $operation->operationId,
                'asset_id' => $assetId,
                'exception' => $journalError,
            ]);

            throw RevertException::of(
                RevertFailure::RecoveryRequired,
                'The asset revert committed, but its operation journal requires recovery.',
                ['operationId' => $operation->operationId],
                $journalError,
            );
        }

        $this->logger->info('Asset Pilot: reverted audit entry {id}, asset {assetId} moved back to {path}', [
            'id' => $parentAuditId,
            'assetId' => $assetId,
            'path' => $targetPath,
        ]);

        return new RevertResult(
            $assetId,
            $sourcePath,
            $targetPath,
            $observerErrors === [] ? null : 'The asset was reverted, but an observer did not complete.',
        );
    }

    protected function classifyPersistedRevert(int $assetId, string $sourcePath, string $targetPath): OperationStatus
    {
        try {
            $asset = $this->loadAsset($assetId);
            if ($asset === null || $asset instanceof Asset\Folder) {
                return OperationStatus::RecoveryRequired;
            }
            $path = $asset->getRealFullPath();
            if ($path === $targetPath) {
                return OperationStatus::Completed;
            }
            if ($path === $sourcePath) {
                return OperationStatus::Failed;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to reconcile the persisted revert state.', [
                'asset_id' => $assetId,
                'exception' => $e,
            ]);
        }

        return OperationStatus::RecoveryRequired;
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id, ['force' => true]);
    }

    protected function nearestExistingFolder(string $path): ?Asset\Folder
    {
        return AssetFolders::nearestExisting($path);
    }

    protected function createFolder(string $path): Asset\Folder
    {
        return Asset\Service::createFolderByPath($path);
    }

    protected function assetAtPath(string $path): ?Asset
    {
        return Asset::getByPath($path);
    }

    // The post-update listener must see either the processing or recently-moved guard.
    protected function saveReverted(Asset $asset, int $assetId): void
    {
        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $this->loopGuard->refreshAsset($assetId);
            $asset->save(['versionNote' => 'Asset Pilot: reverted a previous move']);
            $this->loopGuard->markAssetRecentlyMoved($assetId);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
        }
    }
}
