<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\PropertyType;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\DriftItem;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\MovePlan;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleMatch;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
use Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy;
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
        protected readonly OperationJournalInterface $operationJournal,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly ElementAuthorization $authorization,
        protected readonly OrganizePlanFingerprint $planFingerprints,
        protected readonly int $maxObjectReplays = 3,
    ) {
        if ($this->maxObjectReplays < 1) {
            throw new \InvalidArgumentException('The maximum object replay count must be positive.');
        }
    }

    /** @return OperationResult[] */
    public function organize(
        AbstractObject $object,
        TriggerType $triggerType = TriggerType::ObjectSave,
        ?string $ruleName = null,
        ?string $expectedFingerprint = null,
    ): array {
        return $this->organizeWithProgress($object, $triggerType, $ruleName, $expectedFingerprint, null);
    }

    /** @return OperationResult[] */
    public function organizeWithHeartbeat(
        AbstractObject $object,
        TriggerType $triggerType,
        callable $heartbeat,
        ?string $ruleName = null,
        ?string $expectedFingerprint = null,
    ): array {
        return $this->organizeWithProgress($object, $triggerType, $ruleName, $expectedFingerprint, $heartbeat);
    }

    /** @return OperationResult[] */
    private function organizeWithProgress(
        AbstractObject $object,
        TriggerType $triggerType,
        ?string $ruleName,
        ?string $expectedFingerprint,
        ?callable $heartbeat,
    ): array {
        $results = [];
        $objectId = (int) $object->getId();

        for ($pass = 1; ; ++$pass) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            array_push($results, ...$this->organizeCurrentState($object, $triggerType, $ruleName, $expectedFingerprint, $heartbeat));
            if (!$this->loopGuard->consumeObjectDirty($objectId)) {
                break;
            }
            if ($expectedFingerprint !== null) {
                break;
            }
            if ($pass >= $this->maxObjectReplays) {
                throw new \RuntimeException(sprintf(
                    'Object %d kept changing during organization; retry after writes have settled.',
                    $objectId,
                ));
            }

            $object = $this->reloadObject($objectId);
            if ($object === null) {
                break;
            }

            $this->logger->info('Asset Pilot: object {id} changed during organization; processing its latest state', [
                'id' => $objectId,
            ]);
        }

        return $results;
    }

    /** @return OperationResult[] */
    private function organizeCurrentState(
        AbstractObject $object,
        TriggerType $triggerType,
        ?string $ruleName,
        ?string $expectedFingerprint,
        ?callable $heartbeat,
    ): array {
        $objectId = (int) $object->getId();

        if (!$this->authorization->isAllowed($object, 'publish')) {
            $this->logger->warning('Asset Pilot: actor is not permitted to organize object {id}', ['id' => $objectId]);

            return [];
        }

        if (!$this->loopGuard->acquireObject($objectId)) {
            $this->logger->debug('Asset Pilot: object {id} is already being organized by another job, skipping', [
                'id' => $objectId,
            ]);

            return [];
        }

        $this->loopGuard->markObjectProcessing($objectId);

        try {
            $object = $this->validatedObjectState($object, $triggerType, $ruleName, $expectedFingerprint);

            return $this->organizeLockedState($object, $triggerType, $ruleName, $heartbeat);
        } finally {
            $this->loopGuard->unmarkObjectProcessing($objectId);
            $this->loopGuard->releaseObject($objectId);
        }
    }

    private function validatedObjectState(
        AbstractObject $object,
        TriggerType $triggerType,
        ?string $ruleName,
        ?string $expectedFingerprint,
    ): AbstractObject {
        if ($expectedFingerprint === null) {
            return $object;
        }

        $objectId = (int) $object->getId();
        $object = $this->reloadObject($objectId) ?? throw new StaleApplyPlanException($objectId);
        if (!$this->authorization->isAllowed($object, 'publish')) {
            throw new StaleApplyPlanException($objectId);
        }

        $operations = $this->dryRun($object, $triggerType, $ruleName);
        $fingerprint = $this->planFingerprints->forOperations($object, $operations);
        if (!hash_equals($expectedFingerprint, $fingerprint)) {
            throw new StaleApplyPlanException($objectId);
        }

        return $object;
    }

    /** @return list<OperationResult> */
    private function organizeLockedState(AbstractObject $object, TriggerType $triggerType, ?string $ruleName, ?callable $heartbeat): array
    {
        $objectId = (int) $object->getId();
        $fieldInfos = $this->fieldExtractor->extract($object);
        $this->logger->info('Asset Pilot: organizing assets for {class}:{id} ({fieldCount} asset fields)', [
            'class' => $this->resolveObjectClass($object),
            'id' => $objectId,
            'fieldCount' => count($fieldInfos),
        ]);

        $results = [];
        foreach ($this->bestMatches($object, $fieldInfos, $ruleName) as $candidate) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $this->loopGuard->refreshObject($objectId);
            $asset = $candidate['asset'];
            $match = $candidate['match'];
            $plan = $this->movePlanner->plan($asset, $object, $match->rule, $match->resolvedPath, $triggerType, dryRun: false);
            $results[] = $this->executeMove($asset, $plan, $object, $match->rule, $triggerType);
        }

        $this->logOrganizeResult($object, $results);

        return $results;
    }

    /** @param list<OperationResult> $results */
    private function logOrganizeResult(AbstractObject $object, array $results): void
    {
        $moved = count(array_filter($results, static fn (OperationResult $result): bool => in_array($result->status, [OperationStatus::Completed, OperationStatus::CompletedWithObserverError], true)));
        $skipped = count(array_filter($results, static fn (OperationResult $result): bool => $result->status === OperationStatus::Skipped));
        $failed = count(array_filter($results, static fn (OperationResult $result): bool => $result->status === OperationStatus::Failed));

        $this->logger->info('Asset Pilot: finished organizing {class}:{id} - {moved} moved, {skipped} skipped, {failed} failed', [
            'class' => $this->resolveObjectClass($object),
            'id' => $object->getId(),
            'moved' => $moved,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    /** @return MoveOperation[] */
    public function dryRun(AbstractObject $object, TriggerType $triggerType = TriggerType::Manual, ?string $ruleName = null): array
    {
        if (!$this->authorization->isAllowed($object, 'view')) {
            return [];
        }

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

    /** @param list<MoveOperation> $operations */
    public function preflightApply(array $operations): ?string
    {
        foreach ($operations as $operation) {
            if ($operation->status !== OperationStatus::Pending) {
                continue;
            }

            $asset = $this->loadAssetById($operation->assetId);
            if ($asset === null
                || !$this->authorization->isAllowed($asset, 'view')
                || !$this->authorization->isAllowed($asset, 'publish')
            ) {
                return 'Source asset mutation is not permitted.';
            }

            $targetParent = $this->nearestExistingFolder(dirname($operation->targetPath));
            if ($targetParent === null || !$this->authorization->isAllowed($targetParent, 'create')) {
                return 'Target path creation is not permitted.';
            }
        }

        return null;
    }

    /** @return list<DriftItem> */
    public function analyzeDrift(AbstractObject $object, ?string $ruleName = null): array
    {
        if (!$this->authorization->isAllowed($object, 'view')) {
            return [];
        }

        $items = [];
        $fieldInfos = $this->fieldExtractor->extract($object);
        foreach ($this->bestMatches($object, $fieldInfos, $ruleName) as $candidate) {
            $asset = $candidate['asset'];
            $match = $candidate['match'];
            $currentPath = $asset->getRealFullPath();
            $assessment = $this->movePlanner->assessDrift($asset, $object, $match->rule, $match->resolvedPath);
            if ($currentPath === $assessment->targetPath) {
                continue;
            }
            $items[] = new DriftItem(
                (int) $asset->getId(),
                $currentPath,
                $assessment->targetPath,
                $match->rule->name,
                $assessment->eligibility,
                $assessment->reason,
            );
        }

        return $items;
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
                if (!$this->authorization->isAllowed($asset, 'view')) {
                    continue;
                }
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
        return $this->organizeBulkDetailed($objectIds, $triggerType, $progressCallback, $dispatchedAt)->results;
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $expectedFingerprints
     */
    public function organizeBulkDetailed(
        array $objectIds,
        TriggerType $triggerType,
        ?callable $progressCallback = null,
        ?int $dispatchedAt = null,
        ?callable $staleCallback = null,
        ?callable $shouldCancel = null,
        ?callable $beforeObject = null,
        array $expectedFingerprints = [],
        ?callable $heartbeat = null,
        ?callable $afterObject = null,
    ): BulkOrganizeReport {
        $allResults = [];
        $objectResults = [];
        $observerWarnings = [];
        $total = count($objectIds);

        $this->logger->info('Asset Pilot: starting bulk organization for {count} objects', ['count' => $total]);
        if (NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new BulkOrganizeEvent($objectIds, $triggerType),
            AssetPilotEvents::BULK_STARTED,
            $this->logger,
        ) !== []) {
            $observerWarnings[] = 'Bulk-start observer delivery failed.';
        }

        foreach ($objectIds as $index => $objectId) {
            if ($shouldCancel !== null && $shouldCancel()) {
                break;
            }
            if ($beforeObject !== null && $beforeObject($objectId) === false) {
                continue;
            }

            $resultOffset = count($objectResults);
            try {
                $object = $this->loadObject($objectId);
                if ($object === null) {
                    $reason = 'Object no longer exists.';
                    $this->logger->warning('Asset Pilot: object {id} not found', ['id' => $objectId]);
                    $objectResults[] = new BulkObjectResult($objectId, BulkObjectStatus::Failed, $reason);
                    continue;
                }

                if (!$this->authorization->isAllowed($object, 'publish')) {
                    $reason = 'The initiating actor is no longer permitted to publish this object.';
                    $this->logger->warning('Asset Pilot: actor is not permitted to organize object {id}', ['id' => $objectId]);
                    $objectResults[] = new BulkObjectResult($objectId, BulkObjectStatus::Failed, $reason);
                    continue;
                }

                if (($dispatchedAt ?? 0) > 0 && $object instanceof Concrete && $object->getModificationDate() > $dispatchedAt) {
                    $reason = 'Object changed after this bulk run was dispatched.';
                    $this->logger->info('Asset Pilot: skipping stale object {id} in bulk run', ['id' => $objectId]);
                    if ($staleCallback !== null) {
                        $staleCallback($objectId);
                        $reason .= ' A replacement job was queued.';
                    }
                    $objectResults[] = new BulkObjectResult($objectId, BulkObjectStatus::Skipped, $reason);
                    continue;
                }

                $results = $heartbeat === null
                    ? $this->organize(
                        $object,
                        $triggerType,
                        expectedFingerprint: $expectedFingerprints[$objectId] ?? null,
                    )
                    : $this->organizeWithHeartbeat(
                        $object,
                        $triggerType,
                        fn () => $heartbeat($objectId),
                        expectedFingerprint: $expectedFingerprints[$objectId] ?? null,
                    );
                array_push($allResults, ...$results);
                $objectResults[] = $this->objectResult($objectId, $results);
            } catch (StaleApplyPlanException) {
                $objectResults[] = new BulkObjectResult(
                    $objectId,
                    BulkObjectStatus::Skipped,
                    'Object changed after preview; immutable plan was not applied.',
                );
            } catch (\Throwable $e) {
                if (RetryableInfrastructureFailure::matches($e)) {
                    throw $e;
                }
                $this->logger->error('Asset Pilot: error organizing object {id}: {error}', [
                    'id' => $objectId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $objectResults[] = new BulkObjectResult($objectId, BulkObjectStatus::Failed, 'Unexpected error while organizing this object.');
            } finally {
                if ($afterObject !== null && count($objectResults) > $resultOffset) {
                    $afterObject($objectResults[array_key_last($objectResults)]);
                }
                if ($progressCallback !== null) {
                    $progressCallback($index + 1, $total, $objectId);
                }
            }
        }

        $report = new BulkOrganizeReport($allResults, $objectResults);
        $this->logger->info('Asset Pilot: bulk organization complete - {succeeded} succeeded, {skipped} skipped, {failed} failed', [
            'succeeded' => $report->succeededCount(),
            'skipped' => $report->skippedCount(),
            'failed' => $report->failedCount(),
        ]);
        if (NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new BulkOrganizeEvent($objectIds, $triggerType, $allResults, $objectResults),
            AssetPilotEvents::BULK_COMPLETED,
            $this->logger,
        ) !== []) {
            $observerWarnings[] = 'Bulk-completed observer delivery failed.';
        }

        return new BulkOrganizeReport($allResults, $objectResults, $observerWarnings);
    }

    /** @param list<OperationResult> $results */
    private function objectResult(int $objectId, array $results): BulkObjectResult
    {
        if ($results === []) {
            return new BulkObjectResult($objectId, BulkObjectStatus::Skipped, 'No matching asset operations.', 0);
        }

        $failed = count(array_filter($results, static fn (OperationResult $result): bool => $result->status === OperationStatus::Failed));
        if ($failed > 0) {
            return new BulkObjectResult($objectId, BulkObjectStatus::Failed, sprintf('%d asset operation(s) failed.', $failed), count($results));
        }

        $completed = count(array_filter(
            $results,
            static fn (OperationResult $result): bool => in_array($result->status, [OperationStatus::Completed, OperationStatus::CompletedWithObserverError], true),
        ));
        if ($completed > 0) {
            return new BulkObjectResult($objectId, BulkObjectStatus::Succeeded, operationCount: count($results));
        }

        return new BulkObjectResult($objectId, BulkObjectStatus::Skipped, 'Every asset operation was skipped.', count($results));
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
        $planned = $this->operation(
            $assetId,
            $sourcePath,
            $plan->targetPath,
            $objectId,
            $objectClass,
            $rule,
            $triggerType,
            OperationStatus::Pending,
        );

        if ($plan->isSkip()) {
            $this->logger->debug('Asset Pilot: asset {id} skipped - {reason}', [
                'id' => $assetId,
                'reason' => $plan->skipReason,
            ]);

            return $this->skipMove($planned, $startTime, (string) $plan->skipReason);
        }

        if (!$this->loopGuard->acquireAsset($assetId)) {
            $this->logger->info('Asset Pilot: asset {id} is being moved by another job, skipping', [
                'id' => $assetId,
            ]);

            return $this->skipMove($planned, $startTime, 'Asset is being processed by another job');
        }

        try {
            return $this->executeAssetLockedMove($asset, $plan, $object, $rule, $triggerType, $planned, $startTime);
        } finally {
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    private function executeAssetLockedMove(
        Asset $asset,
        MovePlan $plan,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
        MoveOperation $planned,
        int $startTime,
    ): OperationResult {
        $liveAsset = $this->reloadAsset($asset);
        if ($liveAsset === null || $liveAsset instanceof Asset\Folder) {
            return $this->skipMove($planned, $startTime, 'Asset no longer exists');
        }

        $reason = $this->assetGuardFailure($liveAsset, $planned, $rule);
        if ($reason !== null) {
            return $this->skipMove($planned, $startTime, $reason);
        }
        if (!$this->loopGuard->acquireTarget($plan->targetPath)) {
            return $this->skipMove($planned, $startTime, 'Target path is being allocated by another job');
        }

        try {
            return $this->executeTargetLockedMove($liveAsset, $plan, $object, $rule, $triggerType, $planned, $startTime);
        } finally {
            $this->loopGuard->releaseTarget($plan->targetPath);
        }
    }

    private function assetGuardFailure(Asset $asset, MoveOperation $planned, Rule $rule): ?string
    {
        if ($asset->getRealFullPath() !== $planned->sourcePath) {
            return 'Asset changed after planning';
        }
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            return 'Not permitted to move this asset';
        }
        if ($rule->strategy === MoveStrategy::FirstAssignment
            && $asset->getProperty(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY) === true
        ) {
            return 'Asset was already assigned';
        }

        return null;
    }

    private function executeTargetLockedMove(
        Asset $asset,
        MovePlan $plan,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
        MoveOperation $planned,
        int $startTime,
    ): OperationResult {
        if ($this->loadAssetAtPath($plan->targetPath) !== null) {
            return $this->skipMove($planned, $startTime, 'Target path is no longer available');
        }

        $targetParent = $this->nearestExistingFolder((string) $plan->folderPath);
        if ($targetParent !== null && !$this->authorization->isAllowed($targetParent, 'create')) {
            return $this->skipMove($planned, $startTime, 'Not permitted to create the target path');
        }

        $this->loopGuard->refreshAsset($planned->assetId);
        $this->loopGuard->refreshTarget($plan->targetPath);
        $operation = $this->operationJournal->begin($this->intent(
            $planned->assetId,
            $planned->sourcePath,
            $planned->targetPath,
            $planned->objectId,
            $planned->objectClass,
            $rule,
            $triggerType,
        ));

        try {
            $this->saveMove($asset, $plan, $rule);
        } catch (\Throwable $e) {
            return $this->finalizeMoveState($operation, $asset, $planned->sourcePath, $planned->targetPath, $object, $rule, $triggerType, $startTime, $e);
        }

        return $this->finalizeMoveState($operation, $asset, $planned->sourcePath, $planned->targetPath, $object, $rule, $triggerType, $startTime);
    }

    private function saveMove(Asset $asset, MovePlan $plan, Rule $rule): void
    {
        $assetId = (int) $asset->getId();
        $asset->setParent($this->createFolderIfNeeded((string) $plan->folderPath));
        $asset->setFilename((string) $plan->targetFilename);
        if ($rule->strategy === MoveStrategy::FirstAssignment) {
            $asset->setProperty(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY, PropertyType::Bool->value, true);
        }

        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $this->loopGuard->refreshAsset($assetId);
            $this->loopGuard->refreshTarget($plan->targetPath);
            $asset->save(['versionNote' => 'Asset Pilot: organized by rule "' . $rule->name . '" -> ' . $plan->targetPath]);
            $this->loopGuard->markAssetRecentlyMoved($assetId);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
        }
    }

    private function completeMove(
        OperationHandle $operationHandle,
        Asset $asset,
        string $sourcePath,
        string $targetPath,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
        int $durationMs,
    ): OperationResult {
        $assetId = (int) $asset->getId();
        $errors = [];
        $operation = $this->operation($assetId, $sourcePath, $targetPath, (int) $object->getId(), $this->resolveObjectClass($object), $rule, $triggerType, OperationStatus::Completed, durationMs: $durationMs);

        $observerErrors = NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new AssetMoveEvent($asset, $sourcePath, $targetPath, $object, $rule, $triggerType, operation: $operation),
            AssetPilotEvents::POST_MOVE,
            $this->logger,
            ['asset_id' => $assetId],
        );
        if ($observerErrors !== []) {
            $errors[] = 'Post-move observer delivery failed.';
        }

        if ($errors !== []) {
            $operation = $this->operation($assetId, $sourcePath, $targetPath, (int) $object->getId(), $this->resolveObjectClass($object), $rule, $triggerType, OperationStatus::CompletedWithObserverError, implode('; ', $errors), $durationMs);
        }
        $this->operationJournal->complete($operationHandle, $operation->status, $operation->errorMessage, $durationMs);

        $this->logger->info('Asset Pilot: moved asset {id} from "{source}" to "{target}" (rule: {rule}, {duration}ms)', [
            'id' => $assetId,
            'source' => $sourcePath,
            'target' => $targetPath,
            'rule' => $rule->name,
            'duration' => $durationMs,
        ]);

        return $errors === []
            ? OperationResult::success($operation)
            : OperationResult::completedWithObserverError(implode('; ', $errors), $operation);
    }

    private function deliverFailureObservers(
        Asset $asset,
        string $sourcePath,
        string $targetPath,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
        MoveOperation $operation,
        \Throwable $throwable,
    ): void {
        NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new AssetMoveEvent($asset, $sourcePath, $targetPath, $object, $rule, $triggerType, operation: $operation, throwable: $throwable),
            AssetPilotEvents::MOVE_FAILED,
            $this->logger,
            ['asset_id' => $asset->getId()],
        );
    }

    private function finalizeMoveState(
        OperationHandle $operationHandle,
        Asset $asset,
        string $sourcePath,
        string $targetPath,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
        int $startTime,
        ?\Throwable $cause = null,
    ): OperationResult {
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
        $status = $this->classifyPersistedMove(
            (int) $asset->getId(),
            $sourcePath,
            $targetPath,
            $rule->strategy === MoveStrategy::FirstAssignment,
        );

        if ($status === OperationStatus::Completed) {
            try {
                return $this->completeMove($operationHandle, $asset, $sourcePath, $targetPath, $object, $rule, $triggerType, $durationMs);
            } catch (\Throwable $journalError) {
                $this->logger->critical('Asset Pilot: committed move requires journal recovery.', [
                    'asset_id' => $asset->getId(),
                    'operation_id' => $operationHandle->operationId,
                    'exception' => $journalError,
                ]);

                return OperationResult::recoveryRequired(
                    'The asset move committed, but its operation journal requires recovery.',
                    $operationHandle->intent->toMoveOperation(OperationStatus::RecoveryRequired, 'Operation journal completion failed.', $durationMs),
                );
            }
        }

        if ($status === OperationStatus::RecoveryRequired) {
            return $this->completeUncertainMove($operationHandle, $asset, $durationMs);
        }

        return $this->completeFailedMove($operationHandle, $asset, $sourcePath, $targetPath, $object, $rule, $triggerType, $durationMs, $cause);
    }

    private function completeUncertainMove(OperationHandle $operation, Asset $asset, int $durationMs): OperationResult
    {
        $message = 'The persisted asset state is uncertain and requires recovery.';
        try {
            $this->operationJournal->complete($operation, OperationStatus::RecoveryRequired, $message, $durationMs);
        } catch (\Throwable $journalError) {
            $this->logger->critical('Asset Pilot: uncertain move could not be marked for recovery.', [
                'asset_id' => $asset->getId(),
                'operation_id' => $operation->operationId,
                'exception' => $journalError,
            ]);
        }

        return OperationResult::recoveryRequired(
            $message,
            $operation->intent->toMoveOperation(OperationStatus::RecoveryRequired, $message, $durationMs),
        );
    }

    private function completeFailedMove(
        OperationHandle $operationHandle,
        Asset $asset,
        string $sourcePath,
        string $targetPath,
        AbstractObject $object,
        Rule $rule,
        TriggerType $triggerType,
        int $durationMs,
        ?\Throwable $cause,
    ): OperationResult {
        $publicError = 'Asset move failed.';
        $operation = $operationHandle->intent->toMoveOperation(OperationStatus::Failed, $publicError, $durationMs);
        $this->operationJournal->complete($operationHandle, OperationStatus::Failed, $publicError, $durationMs);
        $failure = $cause ?? new \RuntimeException('The asset move postcondition was not satisfied.');
        $this->deliverFailureObservers($asset, $sourcePath, $targetPath, $object, $rule, $triggerType, $operation, $failure);

        $this->logger->error('Asset Pilot: failed to move asset {id}: {error}', [
            'id' => $asset->getId(),
            'error' => $failure->getMessage(),
            'exception' => $failure,
        ]);
        if ($cause !== null && RetryableInfrastructureFailure::matches($cause)) {
            throw $cause;
        }

        return OperationResult::failed($publicError, $operation);
    }

    protected function classifyPersistedMove(
        int $assetId,
        string $sourcePath,
        string $targetPath,
        bool $requiresFirstAssignment,
    ): OperationStatus {
        try {
            $asset = $this->loadAssetById($assetId);
            if ($asset === null || $asset instanceof Asset\Folder) {
                return OperationStatus::RecoveryRequired;
            }

            $path = $asset->getRealFullPath();
            $isAssigned = $asset->getProperty(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY) === true;
            if ($path === $targetPath && (!$requiresFirstAssignment || $isAssigned)) {
                return OperationStatus::Completed;
            }
            if ($path === $sourcePath && (!$requiresFirstAssignment || !$isAssigned)) {
                return OperationStatus::Failed;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to reconcile the persisted move state.', [
                'asset_id' => $assetId,
                'exception' => $e,
            ]);
        }

        return OperationStatus::RecoveryRequired;
    }

    private function intent(
        int $assetId,
        string $sourcePath,
        string $targetPath,
        int $objectId,
        string $objectClass,
        Rule $rule,
        TriggerType $triggerType,
    ): OperationIntent {
        return new OperationIntent(
            OperationKind::Move,
            $assetId,
            $sourcePath,
            $targetPath,
            $objectId,
            $objectClass,
            $rule->name,
            $triggerType,
            $this->authorization->currentActor(),
            [
                'rule' => $rule->toConfigArray(),
                'actions' => $rule->actions,
                'firstAssignment' => $rule->strategy === MoveStrategy::FirstAssignment,
            ],
        );
    }

    private function skipMove(MoveOperation $planned, int $startTime, string $reason): OperationResult
    {
        $operation = new MoveOperation(
            $planned->assetId,
            $planned->sourcePath,
            $planned->targetPath,
            $planned->objectId,
            $planned->objectClass,
            $planned->ruleName,
            OperationStatus::Skipped,
            $planned->triggerType,
            $reason,
            (int) ((hrtime(true) - $startTime) / 1_000_000),
            $planned->userId,
            $planned->createdAt,
        );

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
            userId: $this->authorization->currentActor()->userId,
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

    protected function reloadAsset(Asset $asset): ?Asset
    {
        return Asset::getById((int) $asset->getId(), ['force' => true]);
    }

    protected function loadAssetById(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }

    protected function loadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId);
    }

    protected function reloadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId, ['force' => true]);
    }

    protected function loadAssetAtPath(string $path): ?Asset
    {
        return Asset::getByPath($path);
    }

    protected function nearestExistingFolder(string $path): ?Asset\Folder
    {
        return AssetFolders::nearestExisting($path);
    }
}
