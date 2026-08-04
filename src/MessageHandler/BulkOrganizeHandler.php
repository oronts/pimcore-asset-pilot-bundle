<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Exception\LostRunItemOwnershipException;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrainInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\RetryableInfrastructureFailure;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
class BulkOrganizeHandler
{
    use PulsesRunItemLease;
    public function __construct(
        protected readonly AssetOrganizerInterface $organizer,
        protected readonly OrganizeDispatcherInterface $dispatcher,
        protected readonly ActorContextStore $actors,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly OperationRunStoreInterface $runs,
        protected readonly OrganizePlanFingerprint $planFingerprints,
        protected readonly ObjectSaveDrainInterface $drain,
    ) {}

    public function __invoke(BulkOrganizeMessage $message): void
    {
        $this->process($message);
    }

    private function process(BulkOrganizeMessage $message): void
    {
        $actor = new ActorContext($message->actorType, $message->actorUserId);
        if (!$this->resumeTrackedRun($message)) {
            return;
        }

        $this->logger->info('Asset Pilot: processing bulk organization for {count} objects (trigger: {trigger})', [
            'count' => count($message->objectIds),
            'trigger' => $message->triggerType->value,
        ]);

        $activeItemKey = null;
        $lockConflict = false;
        try {
            $objectIds = $this->plannedObjectIds($message, $actor);
            if ($message->expectedFingerprints !== [] && $objectIds === []) {
                if ($message->runId !== null) {
                    $this->runs->finish($message->runId);
                }

                return;
            }

            $report = $this->organizeBatch($message, $actor, $objectIds, $activeItemKey, $lockConflict);

            if ($message->runId === null) {
                $this->requeueUntrackedDirtyObjects($message, $actor);
            }
            $this->recordReport($report);
            if ($lockConflict) {
                // Retry before finalizing: finishing here could throw and route a concurrently-owned item into failBatch.
                throw new RecoverableMessageHandlingException('An operation run item is already being processed.');
            }
            if ($message->runId !== null) {
                $this->runs->finish($message->runId);
            }
        } catch (\Throwable $e) {
            if ($e instanceof RecoverableMessageHandlingException) {
                throw $e;
            }
            if ($e instanceof LostRunItemOwnershipException) {
                throw $e;
            }
            if (RetryableInfrastructureFailure::matches($e)) {
                throw $e;
            }
            if ($lockConflict) {
                // A concurrent worker holds at least one item's lock; retry the whole message rather than running
                // failBatch, whose tokenless cleanup could terminalize the item that worker is about to claim.
                throw new RecoverableMessageHandlingException('An operation run item is already being processed.', 0, $e);
            }

            $this->failBatch($message, $e);
        } finally {
            if ($message->runId !== null && $activeItemKey !== null) {
                $this->loopGuard->releaseOperationRunItem($message->runId, $activeItemKey);
            }
        }
    }

    private function resumeTrackedRun(BulkOrganizeMessage $message): bool
    {
        if ($message->runId === null) {
            return true;
        }

        if ($this->runs->isCancellationRequested($message->runId)) {
            $this->cancelOpenItems($message);

            return false;
        }
        if (!$this->runs->resume($message->runId)) {
            return false;
        }
        if ($this->runs->isCancellationRequested($message->runId)) {
            $this->cancelOpenItems($message);

            return false;
        }

        return true;
    }

    /** @param list<int> $objectIds */
    private function organizeBatch(
        BulkOrganizeMessage $message,
        ActorContext $actor,
        array $objectIds,
        ?string &$activeItemKey,
        bool &$lockConflict,
    ): BulkOrganizeReport {
        return $this->actors->runAs(
            $actor,
            function () use ($objectIds, $message, $actor, &$activeItemKey, &$lockConflict): BulkOrganizeReport {
                return $this->organizer->organizeBulkDetailed(
                    $objectIds,
                    $message->triggerType,
                    dispatchedAt: $message->dispatchedAt,
                    staleCallback: $message->expectedFingerprints === []
                        ? fn (int $objectId) => $this->dispatcher->dispatchObject($objectId, $message->triggerType, $actor)
                        : null,
                    shouldCancel: $message->runId === null ? null : fn (): bool => $this->runs->isCancellationRequested($message->runId),
                    beforeObject: $message->runId === null ? null : function (int $objectId) use ($message, &$activeItemKey, &$lockConflict): bool {
                        return $this->beginItem($message, $objectId, $activeItemKey, $lockConflict);
                    },
                    expectedFingerprints: $message->expectedFingerprints,
                    heartbeat: $message->runId === null ? null : fn (int $objectId) => $this->pulseRunItemLease($message->runId, $this->itemKey($objectId)),
                    afterObject: $message->runId === null ? null : function (BulkObjectResult $result) use ($message, $actor, &$activeItemKey): bool {
                        return $this->completeAndReleaseItem($message, $actor, $result, $activeItemKey);
                    },
                );
            },
        );
    }

    private function beginItem(BulkOrganizeMessage $message, int $objectId, ?string &$activeItemKey, bool &$lockConflict): bool
    {
        $itemKey = $this->itemKey($objectId);
        if (!$this->loopGuard->acquireOperationRunItem((string) $message->runId, $itemKey)) {
            $lockConflict = true;

            return false;
        }
        $token = $this->loopGuard->beginOperationRunItemLease((string) $message->runId, $itemKey);
        // Release on ANY exit from resumeItem (throw or false), not only the false branch: releasing is the
        // only thing that clears the minted token from LoopGuard. If resumeItem threw after minting, an
        // orphaned token would make failBatch's fenced completeItem match zero rows and hang the run.
        try {
            $claimed = $this->runs->resumeItem((string) $message->runId, $itemKey, $token);
        } catch (\Throwable $e) {
            $this->loopGuard->releaseOperationRunItem((string) $message->runId, $itemKey);

            throw $e;
        }
        if (!$claimed) {
            $this->loopGuard->releaseOperationRunItem((string) $message->runId, $itemKey);

            return false;
        }
        $activeItemKey = $itemKey;

        return true;
    }

    /**
     * Renew both the Symfony item lock and the durable database lease in one heartbeat, aborting the run
     * if either was lost (the item was reclaimed by a redelivery or reconciled as abandoned). Fires often
     * enough — around each asset save — that a legitimately long item never lets its lease expire.
     */
    /** @param-out null $activeItemKey */

    private function completeAndReleaseItem(BulkOrganizeMessage $message, ActorContext $actor, BulkObjectResult $result, ?string &$activeItemKey): bool
    {
        try {
            $this->requeueDirtyObject($message, $actor, $result->objectId);

            return $this->completeObjectResult((string) $message->runId, $result);
        } finally {
            if ($activeItemKey !== null) {
                $this->loopGuard->releaseOperationRunItem((string) $message->runId, $activeItemKey);
                $activeItemKey = null;
            }
        }
    }

    private function requeueDirtyObject(BulkOrganizeMessage $message, ActorContext $actor, int $objectId): void
    {
        // A concurrent save that coalesced into this run (including an immutable-plan one) needs a fresh
        // non-fingerprinted organize. The shared drain is the single owner of dirty-flag release, durable
        // re-dispatch and clearing; a bulk item holds no per-object intent, so drain with a null run id.
        $this->drain->drain($objectId, $message->triggerType, $actor);
    }

    private function requeueUntrackedDirtyObjects(BulkOrganizeMessage $message, ActorContext $actor): void
    {
        foreach ($message->objectIds as $objectId) {
            $this->requeueDirtyObject($message, $actor, $objectId);
        }
    }

    private function recordReport(BulkOrganizeReport $report): void
    {
        $this->logger->info('Asset Pilot: bulk organization complete - {succeeded} succeeded, {skipped} skipped, {failed} failed', [
            'succeeded' => $report->succeededCount(),
            'skipped' => $report->skippedCount(),
            'failed' => $report->failedCount(),
        ]);
        foreach ($report->observerWarnings as $warning) {
            $this->logger->warning('Asset Pilot: {warning}', ['warning' => $warning]);
        }
    }

    private function failBatch(BulkOrganizeMessage $message, \Throwable $exception): void
    {
        $this->logger->error('Asset Pilot: bulk organization failed: {error}', [
            'error' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        if ($message->runId === null) {
            throw $exception;
        }

        foreach ($message->objectIds as $objectId) {
            $itemKey = $this->itemKey($objectId);
            $this->runs->completeItem(
                $message->runId,
                $itemKey,
                OperationRunItemStatus::Failed,
                error: 'Bulk organization failed.',
                token: $this->loopGuard->operationRunItemToken($message->runId, $itemKey),
            );
        }
        $this->runs->finish($message->runId);
    }

    private function completeObjectResult(string $runId, BulkObjectResult $result): bool
    {
        $itemKey = $this->itemKey($result->objectId);

        return $this->runs->completeItem(
            $runId,
            $itemKey,
            match ($result->status) {
                BulkObjectStatus::Succeeded => OperationRunItemStatus::Completed,
                BulkObjectStatus::Skipped => OperationRunItemStatus::Skipped,
                BulkObjectStatus::Failed => OperationRunItemStatus::Failed,
            },
            ['operationCount' => $result->operationCount],
            $result->reason,
            $this->loopGuard->operationRunItemToken($runId, $itemKey),
        );
    }


    private function cancelOpenItems(BulkOrganizeMessage $message): void
    {
        foreach ($message->objectIds as $objectId) {
            $itemKey = $this->itemKey($objectId);
            if (!$this->loopGuard->acquireOperationRunItem((string) $message->runId, $itemKey)) {
                continue;
            }
            try {
                $this->runs->completeItem(
                    (string) $message->runId,
                    $itemKey,
                    OperationRunItemStatus::Cancelled,
                    error: 'Cancellation was requested before processing.',
                );
            } finally {
                $this->loopGuard->releaseOperationRunItem((string) $message->runId, $itemKey);
            }
        }
        $this->runs->finish((string) $message->runId);
    }

    /** @return list<int> */
    private function plannedObjectIds(BulkOrganizeMessage $message, ActorContext $actor): array
    {
        if ($message->expectedFingerprints === []) {
            return $message->objectIds;
        }

        return $this->actors->runAs($actor, function () use ($message, $actor): array {
            $eligible = [];
            foreach ($message->objectIds as $objectId) {
                $itemKey = $this->itemKey($objectId);
                if ($message->runId !== null && !$this->loopGuard->acquireOperationRunItem($message->runId, $itemKey)) {
                    throw new RecoverableMessageHandlingException('An operation run item is already being processed.');
                }
                try {
                    $expected = $message->expectedFingerprints[$objectId] ?? null;
                    $object = $this->loadObject($objectId);
                    $operations = $object === null ? null : $this->organizer->dryRun($object, $message->triggerType);
                    if ($expected !== null && $object !== null && hash_equals($expected, $this->planFingerprints->forOperations($object, $operations ?? []))) {
                        $eligible[] = $objectId;
                        continue;
                    }

                    $this->logger->info('Asset Pilot: skipping object {id} because it changed after immutable preview', [
                        'id' => $objectId,
                    ]);
                    if ($message->runId !== null) {
                        $this->runs->completeItem(
                            $message->runId,
                            $this->itemKey($objectId),
                            OperationRunItemStatus::Skipped,
                            error: 'Object changed after preview; the immutable plan was not applied.',
                        );
                    }
                    // The plan changed (often a coalesced save marked the object dirty); drain so the new state organizes.
                    $this->requeueDirtyObject($message, $actor, $objectId);
                } finally {
                    if ($message->runId !== null) {
                        $this->loopGuard->releaseOperationRunItem($message->runId, $itemKey);
                    }
                }
            }

            return $eligible;
        });
    }

    protected function loadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId, ['force' => true]);
    }
}
