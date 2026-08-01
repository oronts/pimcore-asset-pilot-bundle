<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\LostRunItemOwnershipException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\BulkRunOutcome;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Model\SingleRunOutcome;
use Pimcore\Model\DataObject\AbstractObject;

/**
 * Owns the synchronous run-item lifecycle (lease claim, heartbeat, completion, and failure bookkeeping)
 * shared by the single and bulk organize entry points. Callers keep run creation, response assembly,
 * logging, and terminal mapping; the executor only runs the loop-safe lease body and reports a typed outcome.
 */
final class SynchronousRunExecutor
{
    public function __construct(
        private readonly AssetOrganizerInterface $organizer,
        private readonly OperationRunStoreInterface $runs,
        private readonly RunItemLease $runItemLease,
        private readonly ObjectSaveDrainInterface $drain,
    ) {}

    public function executeSingle(string $runId, AbstractObject $object, TriggerType $trigger, string $expectedFingerprint, ActorContext $actor): SingleRunOutcome
    {
        $objectId = (int) $object->getId();
        $itemKey = $this->runItemKey($objectId);
        try {
            $started = $this->runItemLease->start($runId, $itemKey);
        } catch (\Throwable) {
            // start() re-throws after releasing its own minted token; a DB failure claiming the item is a
            // claim conflict (409), not an unmapped exception escaping the typed outcome as a 500.
            return SingleRunOutcome::claimConflict();
        }
        if (!$started) {
            return SingleRunOutcome::claimConflict();
        }
        try {
            try {
                $results = $this->organizer->organizeWithHeartbeat(
                    $object,
                    $trigger,
                    fn () => $this->runItemLease->pulse($runId, $itemKey),
                    expectedFingerprint: $expectedFingerprint,
                );
            } catch (StaleApplyPlanException) {
                // The concurrent save that made the plan stale coalesced into this run; drain it so its new state organizes.
                $this->drain->drain($objectId, TriggerType::ObjectSave, $actor);

                return $this->completeItemThenFinish($runId, $itemKey, OperationRunItemStatus::Skipped, [], 'Object changed after preview; the immutable plan was not applied.', SingleRunOutcome::stale());
            }
            $this->drain->drain($objectId, TriggerType::ObjectSave, $actor);

            return $this->completeItemThenFinish($runId, $itemKey, $this->operationItemStatus($results), ['operationCount' => count($results)], null, SingleRunOutcome::completed($results));
        } catch (\Throwable $e) {
            $this->drain->drain($objectId, TriggerType::ObjectSave, $actor);

            return $this->failItemAndRun($runId, $itemKey, $e);
        } finally {
            $this->runItemLease->release($runId, $itemKey);
        }
    }

    /**
     * Complete the terminal item, then finalize the parent as a separate domain: any indeterminate completion
     * or finish() returns leaseLost so the reconciler derives the status; a completed item is never re-failed.
     *
     * @param array<string, int> $data
     */
    private function completeItemThenFinish(string $runId, string $itemKey, OperationRunItemStatus $status, array $data, ?string $error, SingleRunOutcome $onFinalized): SingleRunOutcome
    {
        try {
            $completed = $this->runItemLease->complete($runId, $itemKey, $status, $data, $error);
        } catch (\Throwable) {
            return SingleRunOutcome::leaseLost();
        }
        if (!$completed) {
            return SingleRunOutcome::leaseLost();
        }
        try {
            $finalStatus = $this->runs->finish($runId);
        } catch (\Throwable) {
            return SingleRunOutcome::leaseLost();
        }
        if (!$this->reportsSelfFinalized($finalStatus)) {
            return SingleRunOutcome::leaseLost();
        }

        return $onFinalized;
    }

    /**
     * Organization failed: record the Failed item and fail the run, keeping bookkeeping exceptions inside the
     * typed contract (a throwing completion or fail() returns leaseLost, never an escaping exception).
     */
    private function failItemAndRun(string $runId, string $itemKey, \Throwable $e): SingleRunOutcome
    {
        try {
            $completed = $this->runItemLease->complete($runId, $itemKey, OperationRunItemStatus::Failed, error: 'Organization failed.');
        } catch (\Throwable) {
            return SingleRunOutcome::leaseLost();
        }
        if (!$completed) {
            return SingleRunOutcome::leaseLost();
        }
        try {
            $this->runs->fail($runId, 'Organization failed.');
        } catch (\Throwable) {
            return SingleRunOutcome::leaseLost();
        }

        return SingleRunOutcome::failed($e);
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $fingerprints
     */
    public function executeBulk(string $runId, array $objectIds, TriggerType $trigger, array $fingerprints, ActorContext $actor): BulkRunOutcome
    {
        try {
            $report = $this->runBulkOrganize($runId, $objectIds, $trigger, $fingerprints, $actor);
        } catch (LostRunItemOwnershipException $e) {
            return BulkRunOutcome::ownershipLost($e);
        } catch (\Throwable $e) {
            if (!$this->failOwnedItemsAndRun($runId, $objectIds, 'Bulk organization failed.')) {
                return BulkRunOutcome::ownershipLost(new LostRunItemOwnershipException(
                    'A run item was reclaimed by a concurrent attempt during bulk-failure cleanup; that attempt records it.',
                ));
            }

            return BulkRunOutcome::failed($e);
        }

        // Finalize the parent separately so a throwing finish() reports ownership loss, not a failed run.
        try {
            $status = $this->runs->finish($runId);
        } catch (\Throwable) {
            return BulkRunOutcome::ownershipLost(new LostRunItemOwnershipException(
                'The bulk run items completed but parent finalization threw; a reconciler derives the durable status.',
            ));
        }
        if (!$this->reportsSelfFinalized($status)) {
            return BulkRunOutcome::ownershipLost(new LostRunItemOwnershipException(sprintf(
                'The bulk run did not finalize under this attempt (status %s); another attempt or a reconciler owns it.',
                $status->value,
            )));
        }

        return BulkRunOutcome::completed($report);
    }

    /**
     * The loop-safe bulk organize invocation (run-item lease claim/heartbeat/completion) shared by the
     * controller sync path and the reviewed-selection path; callers own start/finish/failure and mapping.
     *
     * @param list<int>          $objectIds
     * @param array<int, string> $fingerprints
     */
    public function runBulkOrganize(string $runId, array $objectIds, TriggerType $trigger, array $fingerprints, ActorContext $actor): BulkOrganizeReport
    {
        return $this->organizer->organizeBulkDetailed(
            $objectIds,
            $trigger,
            shouldCancel: fn (): bool => $this->runs->isCancellationRequested($runId),
            beforeObject: fn (int $objectId): bool => $this->runItemLease->start($runId, $this->runItemKey($objectId)),
            expectedFingerprints: $fingerprints,
            heartbeat: fn (int $objectId) => $this->runItemLease->pulse($runId, $this->runItemKey($objectId)),
            afterObject: function (BulkObjectResult $result) use ($runId, $actor): bool {
                // Drain before completing so a completion failure cannot skip draining a coalesced save.
                $this->drain->drain($result->objectId, TriggerType::ObjectSave, $actor);

                return $this->runItemLease->complete(
                    $runId,
                    $this->runItemKey($result->objectId),
                    $this->bulkItemStatus($result->status),
                    ['operationCount' => $result->operationCount],
                    $result->reason,
                );
            },
        );
    }

    /** @param list<OperationResult> $results */
    private function operationItemStatus(array $results): OperationRunItemStatus
    {
        if ($results === [] || array_all($results, static fn ($result): bool => $result->status === OperationStatus::Skipped)) {
            return OperationRunItemStatus::Skipped;
        }
        if (array_any($results, static fn ($result): bool => $result->status === OperationStatus::Failed)) {
            return OperationRunItemStatus::Failed;
        }

        return OperationRunItemStatus::Completed;
    }

    private function bulkItemStatus(BulkObjectStatus $status): OperationRunItemStatus
    {
        return match ($status) {
            BulkObjectStatus::Succeeded => OperationRunItemStatus::Completed,
            BulkObjectStatus::Skipped => OperationRunItemStatus::Skipped,
            BulkObjectStatus::Failed => OperationRunItemStatus::Failed,
        };
    }

    public function reportsSelfFinalized(OperationRunStatus $status): bool
    {
        // Failed/Cancelled or non-terminal means another attempt or a reconciler owns the run, not this one.
        return in_array($status, [OperationRunStatus::Completed, OperationRunStatus::Partial, OperationRunStatus::Blocked], true);
    }

    /**
     * Terminalize only the still-owned in-flight item (fenced by its claim token) and fail the run. Returns
     * false when a concurrent attempt reclaimed the owned item, so the caller reports ownership loss instead
     * of failing a run it no longer owns.
     *
     * @param list<int> $objectIds
     */
    public function failOwnedItemsAndRun(string $runId, array $objectIds, string $error): bool
    {
        $fenceHeld = true;
        foreach ($objectIds as $objectId) {
            $itemKey = $this->runItemKey($objectId);
            if ($this->runItemLease->token($runId, $itemKey) === null) {
                continue; // never-started or already-released item: not ours to terminalize.
            }
            try {
                if (!$this->runItemLease->complete($runId, $itemKey, OperationRunItemStatus::Failed, error: $error)) {
                    $fenceHeld = false;
                }
            } catch (\Throwable) {
                // A throwing completion is indeterminate ownership: release the local token and do NOT run the
                // unfenced fail(); reconcileExpiredItemLeases reaps the still-Running item.
                $this->runItemLease->release($runId, $itemKey);
                $fenceHeld = false;
            }
        }
        if ($fenceHeld) {
            try {
                $this->runs->fail($runId, $error);
            } catch (\Throwable) {
                // A throwing fail() is indeterminate finalization: report ownership loss, never leak the exception.
                return false;
            }
        }

        return $fenceHeld;
    }

    private function runItemKey(int $objectId): string
    {
        return 'object:' . $objectId;
    }
}
