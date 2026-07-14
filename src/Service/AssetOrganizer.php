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
use Oronts\AssetPilotBundle\Model\MovePlan;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleMatch;
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
        protected readonly MovePlanner $movePlanner,
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
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
            $fieldInfos = $this->fieldExtractor->extract($object);

            $this->logger->info('Asset Pilot: organizing assets for {class}:{id} ({fieldCount} asset fields)', [
                'class' => $this->resolveObjectClass($object),
                'id' => $object->getId(),
                'fieldCount' => count($fieldInfos),
            ]);

            foreach ($this->bestMatches($object, $fieldInfos, $ruleName) as $candidate) {
                $this->loopGuard->refreshObject($objectId);
                $asset = $candidate['asset'];
                $match = $candidate['match'];
                $plan = $this->movePlanner->plan($asset, $object, $match->rule, $match->resolvedPath, $triggerType, dryRun: false);
                $results[] = $this->executeMove($asset, $plan, $object, $match->rule, $triggerType);
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
        $objectId = (int) $object->getId();
        $objectClass = $this->resolveObjectClass($object);
        $fieldInfos = $this->fieldExtractor->extract($object);

        foreach ($this->bestMatches($object, $fieldInfos, $ruleName) as $candidate) {
            $asset = $candidate['asset'];
            $match = $candidate['match'];
            $assetId = (int) $asset->getId();
            $assetPath = $asset->getRealFullPath();
            $plan = $this->movePlanner->plan($asset, $object, $match->rule, $match->resolvedPath, $triggerType, dryRun: true);
            $status = $plan->isSkip() ? OperationStatus::Skipped : OperationStatus::Pending;
            $operations[] = $this->operation($assetId, $assetPath, $plan->targetPath, $objectId, $objectClass, $match->rule, $triggerType, $status, $plan->skipReason);
        }

        $pendingMoves = array_filter($operations, static fn (MoveOperation $op): bool => $op->status === OperationStatus::Pending);
        $this->logger->info('Asset Pilot: dry run for {class}:{id} found {count} pending moves', [
            'class' => $this->resolveObjectClass($object),
            'id' => $object->getId(),
            'count' => count($pendingMoves),
        ]);

        return $operations;
    }

    /**
     * @param iterable<\Oronts\AssetPilotBundle\Model\AssetFieldInfo> $fieldInfos
     *
     * @return list<array{asset: Asset, match: RuleMatch, field: string, locale: ?string}>
     */
    private function bestMatches(AbstractObject $object, iterable $fieldInfos, ?string $ruleName): array
    {
        $best = [];

        foreach ($fieldInfos as $fieldInfo) {
            foreach ($fieldInfo->assets as $asset) {
                $assetId = (int) $asset->getId();
                $matches = $this->ruleEngine->matchField($object, $asset, $fieldInfo->fieldName, $fieldInfo->locale);

                foreach ($matches as $match) {
                    if ($ruleName !== null && $match->rule->name !== $ruleName) {
                        continue;
                    }

                    $candidate = [
                        'asset' => $asset,
                        'match' => $match,
                        'field' => $fieldInfo->fieldName,
                        'locale' => $fieldInfo->locale,
                    ];

                    if (!isset($best[$assetId]) || $this->precedes($candidate, $best[$assetId])) {
                        $best[$assetId] = $candidate;
                    }
                }
            }
        }

        ksort($best, SORT_NUMERIC);

        return array_values($best);
    }

    /**
     * @param array{match: RuleMatch, field: string, locale: ?string} $candidate
     * @param array{match: RuleMatch, field: string, locale: ?string} $current
     */
    private function precedes(array $candidate, array $current): bool
    {
        if ($candidate['match']->rule->priority !== $current['match']->rule->priority) {
            return $candidate['match']->rule->priority > $current['match']->rule->priority;
        }

        return [
            $candidate['match']->rule->name,
            $candidate['field'],
            $candidate['locale'] ?? '',
        ] < [
            $current['match']->rule->name,
            $current['field'],
            $current['locale'] ?? '',
        ];
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

    protected function executeMove(
        Asset $asset,
        MovePlan $plan,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
    ): OperationResult {
        $startTime = hrtime(true);
        $assetId = (int) $asset->getId();
        $objectId = (int) $object->getId();
        $objectClass = $this->resolveObjectClass($object);
        $sourcePath = $asset->getRealFullPath();

        if ($plan->isSkip()) {
            $this->logger->debug('Asset Pilot: asset {id} skipped - {reason}', [
                'id' => $assetId,
                'reason' => $plan->skipReason,
            ]);

            return $this->skip($assetId, $sourcePath, $plan->targetPath, $objectId, $objectClass, $rule, $triggerType, $startTime, (string) $plan->skipReason);
        }

        // Serialize the actual move: a second job sharing this asset must not mutate it concurrently.
        if (!$this->loopGuard->acquireAsset($assetId)) {
            $this->logger->info('Asset Pilot: asset {id} is being moved by another job, skipping', [
                'id' => $assetId,
            ]);

            return $this->skip($assetId, $sourcePath, $plan->targetPath, $objectId, $objectClass, $rule, $triggerType, $startTime, 'Asset is being processed by another job');
        }

        try {
            $folder = $this->createFolderIfNeeded((string) $plan->folderPath);

            $asset->setParent($folder);
            $asset->setFilename((string) $plan->targetFilename);

            // Mark recently-moved (5min TTL) before unmarking processing so the asset never sits in a
            // window where it is neither flagged — that gap could let async ping-pong slip through when
            // the asset is shared between multiple objects. Only set it once the save actually lands.
            $this->loopGuard->markAssetProcessing($assetId);
            try {
                $asset->save(['versionNote' => 'Asset Pilot: organized by rule "' . $rule->name . '" -> ' . $plan->targetPath]);
                $this->loopGuard->markAssetRecentlyMoved($assetId);
            } finally {
                $this->loopGuard->unmarkAssetProcessing($assetId);
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $operation = $this->operation($assetId, $sourcePath, $plan->targetPath, $objectId, $objectClass, $rule, $triggerType, OperationStatus::Completed, durationMs: $durationMs);

            $this->auditLogger->log($operation);

            $postMoveEvent = new AssetMoveEvent($asset, $sourcePath, $plan->targetPath, $object, $rule, $triggerType, operation: $operation);
            $this->eventDispatcher->dispatch($postMoveEvent, AssetPilotEvents::POST_MOVE);

            $this->logger->info('Asset Pilot: moved asset {id} from "{source}" to "{target}" (rule: {rule}, {duration}ms)', [
                'id' => $assetId,
                'source' => $sourcePath,
                'target' => $plan->targetPath,
                'rule' => $rule->name,
                'duration' => $durationMs,
            ]);

            return OperationResult::success($operation);

        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $operation = $this->operation($assetId, $sourcePath, $plan->targetPath, $objectId, $objectClass, $rule, $triggerType, OperationStatus::Failed, $e->getMessage(), $durationMs);

            $this->auditLogger->log($operation);

            $failedEvent = new AssetMoveEvent($asset, $sourcePath, $plan->targetPath, $object, $rule, $triggerType, operation: $operation, throwable: $e);
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

    protected function createFolderIfNeeded(string $path): Asset\Folder
    {
        $folder = Asset\Service::createFolderByPath($path);

        $this->logger->debug('Asset Pilot: ensured folder exists at "{path}"', ['path' => $path]);

        return $folder;
    }
}
