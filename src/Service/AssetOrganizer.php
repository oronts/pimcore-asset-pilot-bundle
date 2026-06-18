<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Strategy\StrategyResolver;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AssetOrganizer
{
    public function __construct(
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly AssetFieldExtractorInterface $fieldExtractor,
        protected readonly StrategyResolver $strategyResolver,
        protected readonly NamingStrategyInterface $namingStrategy,
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly array $excludeFolders = [],
        protected readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    /** @return OperationResult[] */
    public function organize(AbstractObject $object, TriggerType $triggerType = TriggerType::ObjectSave, ?string $ruleName = null): array
    {
        $objectId = (int) $object->getId();

        if (!$this->loopGuard->acquireObject($objectId)) {
            $this->logger->debug('Asset Pilot: object {id} is already being organized by another job, skipping', [
                'id' => $objectId,
            ]);

            return [];
        }

        // Cache flag the save listeners read to skip the postUpdate this organize run triggers.
        $this->loopGuard->markObjectProcessing($objectId);

        try {
            $results = [];
            $processedAssetIds = [];
            $fieldInfos = $this->fieldExtractor->extract($object);

            $this->logger->info('Asset Pilot: organizing assets for {class}:{id} ({fieldCount} asset fields)', [
                'class' => $this->resolveObjectClass($object),
                'id' => $object->getId(),
                'fieldCount' => count($fieldInfos),
            ]);

            foreach ($fieldInfos as $fieldInfo) {
                foreach ($fieldInfo->assets as $asset) {
                    $assetId = (int) $asset->getId();

                    $this->loopGuard->refreshObject($objectId);

                    // Skip if this asset was already processed by a higher-priority rule
                    // (same asset can appear in multiple fields)
                    if (isset($processedAssetIds[$assetId])) {
                        $this->logger->debug('Asset Pilot: asset {assetId} already processed by rule "{rule}", skipping field "{field}"', [
                            'assetId' => $assetId,
                            'rule' => $processedAssetIds[$assetId],
                            'field' => $fieldInfo->fieldName,
                        ]);
                        continue;
                    }

                    $matches = $this->ruleEngine->matchField($object, $asset, $fieldInfo->fieldName, $fieldInfo->locale);

                    if ($ruleName !== null) {
                        $matches = array_values(array_filter($matches, static fn ($m): bool => $m->rule->name === $ruleName));
                    }

                    if (empty($matches)) {
                        $this->logger->debug('Asset Pilot: no rules matched for asset {assetId} in field "{field}"', [
                            'assetId' => $assetId,
                            'field' => $fieldInfo->fieldName,
                        ]);
                        continue;
                    }

                    // Use highest priority match
                    $match = $matches[0];
                    $processedAssetIds[$assetId] = $match->rule->name;
                    $strategy = $this->strategyResolver->resolve($match->rule);

                    if (!$strategy->resolve($asset, $object, $match->rule)) {
                        $operation = $this->operation($assetId, $asset->getRealFullPath(), $match->resolvedPath, $objectId, $this->resolveObjectClass($object), $match->rule, $triggerType, OperationStatus::Skipped);
                        $this->auditLogger->log($operation);
                        $results[] = OperationResult::skipped('Strategy rejected move', $operation);
                        continue;
                    }

                    $targetFilename = $this->namingStrategy->generateName($asset, $match->resolvedPath);
                    $results[] = $this->moveAsset($asset, $match->resolvedPath, $targetFilename, $object, $match->rule, $triggerType);
                }
            }

            $moved = count(array_filter($results, static fn (OperationResult $r) => $r->status === OperationStatus::Completed));
            $skipped = count(array_filter($results, static fn (OperationResult $r) => $r->status === OperationStatus::Skipped));
            $failed = count(array_filter($results, static fn (OperationResult $r) => $r->status === OperationStatus::Failed));

            $this->logger->info('Asset Pilot: finished organizing {class}:{id} - {moved} moved, {skipped} skipped, {failed} failed', [
                'class' => $this->resolveObjectClass($object),
                'id' => $object->getId(),
                'moved' => $moved,
                'skipped' => $skipped,
                'failed' => $failed,
            ]);

            return $results;
        } finally {
            $this->loopGuard->unmarkObjectProcessing($objectId);
            $this->loopGuard->releaseObject($objectId);
        }
    }

    /** @return MoveOperation[] */
    public function dryRun(AbstractObject $object, TriggerType $triggerType = TriggerType::Manual, ?string $ruleName = null): array
    {
        $operations = [];
        $processedAssetIds = [];
        $objectId = (int) $object->getId();
        $objectClass = $this->resolveObjectClass($object);
        $fieldInfos = $this->fieldExtractor->extract($object);

        foreach ($fieldInfos as $fieldInfo) {
            foreach ($fieldInfo->assets as $asset) {
                $assetId = (int) $asset->getId();

                // Skip if this asset was already processed by a higher-priority rule
                if (isset($processedAssetIds[$assetId])) {
                    continue;
                }

                $matches = $this->ruleEngine->matchField($object, $asset, $fieldInfo->fieldName, $fieldInfo->locale);
                if ($ruleName !== null) {
                    $matches = array_values(array_filter($matches, static fn ($m): bool => $m->rule->name === $ruleName));
                }
                if (empty($matches)) {
                    continue;
                }

                $match = $matches[0];
                $processedAssetIds[$assetId] = true;
                $assetPath = $asset->getRealFullPath();

                // Apply the same gates in the same order as the real pipeline (strategy in organize(),
                // then moveAsset's already-at-target -> PRE_MOVE -> lock -> exclude) so the preview's
                // skip reason matches what the move would actually report.
                $strategy = $this->strategyResolver->resolve($match->rule);
                if (!$strategy->resolve($asset, $object, $match->rule)) {
                    $operations[] = $this->operation($assetId, $assetPath, $match->resolvedPath, $objectId, $objectClass, $match->rule, $triggerType, OperationStatus::Skipped, 'Strategy rejected move');
                    continue;
                }

                $targetFilename = $this->namingStrategy->generateName($asset, $match->resolvedPath);
                $fullTargetPath = rtrim($match->resolvedPath, '/') . '/' . $targetFilename;

                if ($assetPath === $fullTargetPath) {
                    $operations[] = $this->operation($assetId, $assetPath, $fullTargetPath, $objectId, $objectClass, $match->rule, $triggerType, OperationStatus::Skipped, 'Asset already at target path');
                    continue;
                }

                $preMoveEvent = new AssetMoveEvent($asset, $assetPath, $fullTargetPath, $object, $match->rule, $triggerType, dryRun: true);
                $this->eventDispatcher->dispatch($preMoveEvent, AssetPilotEvents::PRE_MOVE);
                if ($preMoveEvent->isCancelled()) {
                    $operations[] = $this->operation($assetId, $assetPath, $fullTargetPath, $objectId, $objectClass, $match->rule, $triggerType, OperationStatus::Skipped, 'Cancelled by event listener');
                    continue;
                }

                if ($asset->hasProperty($this->lockProperty) && $asset->getProperty($this->lockProperty)) {
                    $operations[] = $this->operation($assetId, $assetPath, $fullTargetPath, $objectId, $objectClass, $match->rule, $triggerType, OperationStatus::Skipped, 'Asset is locked');
                    continue;
                }

                $excludedFolder = $this->matchingExcludeFolder($assetPath);
                if ($excludedFolder !== null) {
                    $operations[] = $this->operation($assetId, $assetPath, $fullTargetPath, $objectId, $objectClass, $match->rule, $triggerType, OperationStatus::Skipped, 'Asset is in excluded folder: ' . $excludedFolder);
                    continue;
                }

                $operations[] = $this->operation($assetId, $assetPath, $fullTargetPath, $objectId, $objectClass, $match->rule, $triggerType, OperationStatus::Pending);
            }
        }

        $this->logger->info('Asset Pilot: dry run for {class}:{id} found {count} pending moves', [
            'class' => $this->resolveObjectClass($object),
            'id' => $object->getId(),
            'count' => count($operations),
        ]);

        return $operations;
    }

    /** @return OperationResult[] */
    public function organizeBulk(array $objectIds, TriggerType $triggerType, ?callable $progressCallback = null, ?int $dispatchedAt = null): array
    {
        $allResults = [];
        $total = count($objectIds);

        $this->logger->info('Asset Pilot: starting bulk organization for {count} objects', ['count' => $total]);
        $this->eventDispatcher->dispatch(new BulkOrganizeEvent($objectIds, $triggerType), AssetPilotEvents::BULK_STARTED);

        foreach ($objectIds as $index => $objectId) {
            $object = AbstractObject::getById($objectId);
            if ($object === null) {
                $this->logger->warning('Asset Pilot: object {id} not found, skipping', ['id' => $objectId]);
                continue;
            }

            // Stale-job detection per object: skip if it changed after this batch was dispatched.
            // A missing/zero dispatch time means "no stale check" (matches OrganizeAssetsHandler).
            if (($dispatchedAt ?? 0) > 0 && $object instanceof Concrete && $object->getModificationDate() > $dispatchedAt) {
                $this->logger->info('Asset Pilot: skipping stale object {id} in bulk run (modified after dispatch)', ['id' => $objectId]);
                continue;
            }

            try {
                $results = $this->organize($object, $triggerType);
                array_push($allResults, ...$results);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: error organizing object {id}: {error}', [
                    'id' => $objectId,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($progressCallback !== null) {
                $progressCallback($index + 1, $total, $objectId);
            }
        }

        $this->logger->info('Asset Pilot: bulk organization complete - {total} results', [
            'total' => count($allResults),
        ]);
        $this->eventDispatcher->dispatch(new BulkOrganizeEvent($objectIds, $triggerType, $allResults), AssetPilotEvents::BULK_COMPLETED);

        return $allResults;
    }

    protected function moveAsset(
        Asset $asset,
        string $targetPath,
        string $targetFilename,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
    ): OperationResult {
        $startTime = hrtime(true);
        $assetId = (int) $asset->getId();
        $objectId = (int) $object->getId();
        $objectClass = $this->resolveObjectClass($object);
        $sourcePath = $asset->getRealFullPath();
        $fullTargetPath = rtrim($targetPath, '/') . '/' . $targetFilename;

        // Skip if already at target
        if ($sourcePath === $fullTargetPath) {
            $this->logger->debug('Asset Pilot: asset {id} already at target path, skipping', [
                'id' => $assetId,
            ]);

            return $this->skip($assetId, $sourcePath, $fullTargetPath, $objectId, $objectClass, $rule, $triggerType, $startTime, 'Asset already at target path');
        }

        // Dispatch pre-move event (cancellable)
        $preMoveEvent = new AssetMoveEvent($asset, $sourcePath, $fullTargetPath, $object, $rule, $triggerType);
        $this->eventDispatcher->dispatch($preMoveEvent, AssetPilotEvents::PRE_MOVE);

        if ($preMoveEvent->isCancelled()) {
            $this->logger->info('Asset Pilot: move cancelled by event listener for asset {id}', [
                'id' => $assetId,
            ]);

            return $this->skip($assetId, $sourcePath, $fullTargetPath, $objectId, $objectClass, $rule, $triggerType, $startTime, 'Cancelled by event listener');
        }

        // Check if asset is locked via custom property
        if ($asset->hasProperty($this->lockProperty) && $asset->getProperty($this->lockProperty)) {
            $this->logger->info('Asset Pilot: asset {id} is locked (property: {prop}), skipping move', [
                'id' => $assetId,
                'prop' => $this->lockProperty,
            ]);

            return $this->skip($assetId, $sourcePath, $fullTargetPath, $objectId, $objectClass, $rule, $triggerType, $startTime, 'Asset is locked');
        }

        // Check if asset is in an excluded folder
        $excludedFolder = $this->matchingExcludeFolder($sourcePath);
        if ($excludedFolder !== null) {
            $this->logger->info('Asset Pilot: asset {id} is in excluded folder "{folder}", skipping move', [
                'id' => $assetId,
                'folder' => $excludedFolder,
            ]);

            return $this->skip($assetId, $sourcePath, $fullTargetPath, $objectId, $objectClass, $rule, $triggerType, $startTime, 'Asset is in excluded folder: ' . $excludedFolder);
        }

        // Serialize the actual move: a second job sharing this asset must not mutate it concurrently.
        if (!$this->loopGuard->acquireAsset($assetId)) {
            $this->logger->info('Asset Pilot: asset {id} is being moved by another job, skipping', [
                'id' => $assetId,
            ]);

            return $this->skip($assetId, $sourcePath, $fullTargetPath, $objectId, $objectClass, $rule, $triggerType, $startTime, 'Asset is being processed by another job');
        }

        try {
            // Create folder if needed
            $folder = $this->createFolderIfNeeded($targetPath);

            // Move asset — mark as processing via Redis to prevent AssetUploadListener re-triggering
            $asset->setParent($folder);
            $asset->setFilename($targetFilename);

            // Mark recently-moved (5min TTL) before unmarking processing so the asset never sits in a
            // window where it is neither flagged — that gap could let async ping-pong slip through when
            // the asset is shared between multiple objects. Only set it once the save actually lands.
            $this->loopGuard->markAssetProcessing($assetId);
            try {
                $asset->save();
                $this->loopGuard->markAssetRecentlyMoved($assetId);
            } finally {
                $this->loopGuard->unmarkAssetProcessing($assetId);
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $operation = $this->operation($assetId, $sourcePath, $fullTargetPath, $objectId, $objectClass, $rule, $triggerType, OperationStatus::Completed, durationMs: $durationMs);

            // Log audit entry
            $this->auditLogger->log($operation);

            // Dispatch post-move event
            $postMoveEvent = new AssetMoveEvent($asset, $sourcePath, $fullTargetPath, $object, $rule, $triggerType, operation: $operation);
            $this->eventDispatcher->dispatch($postMoveEvent, AssetPilotEvents::POST_MOVE);

            $this->logger->info('Asset Pilot: moved asset {id} from "{source}" to "{target}" (rule: {rule}, {duration}ms)', [
                'id' => $assetId,
                'source' => $sourcePath,
                'target' => $fullTargetPath,
                'rule' => $rule->name,
                'duration' => $durationMs,
            ]);

            return OperationResult::success($operation);

        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $operation = $this->operation($assetId, $sourcePath, $fullTargetPath, $objectId, $objectClass, $rule, $triggerType, OperationStatus::Failed, $e->getMessage(), $durationMs);

            $this->auditLogger->log($operation);

            $failedEvent = new AssetMoveEvent($asset, $sourcePath, $fullTargetPath, $object, $rule, $triggerType, operation: $operation, throwable: $e);
            $this->eventDispatcher->dispatch($failedEvent, AssetPilotEvents::MOVE_FAILED);

            $this->logger->error('Asset Pilot: failed to move asset {id}: {error}', [
                'id' => $assetId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return OperationResult::failed($e->getMessage(), $operation);
        } finally {
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    protected function skip(
        int $assetId,
        string $sourcePath,
        string $targetPath,
        int $objectId,
        string $objectClass,
        Rule $rule,
        TriggerType $triggerType,
        int $startTime,
        string $reason,
    ): OperationResult {
        $operation = $this->operation($assetId, $sourcePath, $targetPath, $objectId, $objectClass, $rule, $triggerType, OperationStatus::Skipped, $reason, (int) ((hrtime(true) - $startTime) / 1_000_000));

        $this->auditLogger->log($operation);

        return OperationResult::skipped($reason, $operation);
    }

    protected function operation(
        int $assetId,
        string $sourcePath,
        string $targetPath,
        int $objectId,
        string $objectClass,
        Rule $rule,
        TriggerType $triggerType,
        OperationStatus $status,
        ?string $errorMessage = null,
        ?int $durationMs = null,
    ): MoveOperation {
        return new MoveOperation(
            assetId: $assetId,
            sourcePath: $sourcePath,
            targetPath: $targetPath,
            objectId: $objectId,
            objectClass: $objectClass,
            ruleName: $rule->name,
            status: $status,
            triggerType: $triggerType,
            errorMessage: $errorMessage,
            durationMs: $durationMs,
        );
    }

    private function resolveObjectClass(AbstractObject $object): string
    {
        return $object instanceof Concrete ? $object->getClassName() : 'Folder';
    }

    private function matchingExcludeFolder(string $path): ?string
    {
        foreach ($this->excludeFolders as $excludedFolder) {
            if (str_starts_with($path, rtrim($excludedFolder, '/') . '/')) {
                return $excludedFolder;
            }
        }

        return null;
    }

    protected function createFolderIfNeeded(string $path): Asset\Folder
    {
        $folder = Asset\Service::createFolderByPath($path);

        $this->logger->debug('Asset Pilot: ensured folder exists at "{path}"', ['path' => $path]);

        return $folder;
    }
}
