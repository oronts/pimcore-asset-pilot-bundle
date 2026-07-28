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
    ) {}

    public function executeSingle(string $runId, AbstractObject $object, TriggerType $trigger, string $expectedFingerprint): SingleRunOutcome
    {
        $itemKey = $this->runItemKey((int) $object->getId());
        if (!$this->runItemLease->start($runId, $itemKey)) {
            return SingleRunOutcome::claimConflict();
        }
        $itemCompleted = false;
        try {
            $results = $this->organizer->organizeWithHeartbeat(
                $object,
                $trigger,
                fn () => $this->runItemLease->pulse($runId, $itemKey),
                expectedFingerprint: $expectedFingerprint,
            );
            if (!$this->runItemLease->complete($runId, $itemKey, $this->operationItemStatus($results), ['operationCount' => count($results)])) {
                return SingleRunOutcome::leaseLost();
            }
            $itemCompleted = true;
            if (!$this->reportsSelfFinalized($this->runs->finish($runId))) {
                return SingleRunOutcome::leaseLost();
            }

            return SingleRunOutcome::completed($results);
        } catch (StaleApplyPlanException) {
            if (!$this->runItemLease->complete($runId, $itemKey, OperationRunItemStatus::Skipped, error: 'Object changed after preview; the immutable plan was not applied.')) {
                return SingleRunOutcome::leaseLost();
            }
            $itemCompleted = true;
            if (!$this->reportsSelfFinalized($this->runs->finish($runId))) {
                return SingleRunOutcome::leaseLost();
            }

            return SingleRunOutcome::stale();
        } catch (\Throwable $e) {
            // Already terminalized means finalization threw, not a lost claim: do not re-complete or report lease loss.
            if (!$itemCompleted && !$this->runItemLease->complete($runId, $itemKey, OperationRunItemStatus::Failed, error: 'Organization failed.')) {
                return SingleRunOutcome::leaseLost();
            }
            $this->runs->fail($runId, 'Organization failed.');

            return SingleRunOutcome::failed($e);
        } finally {
            $this->runItemLease->release($runId, $itemKey);
        }
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $fingerprints
     */
    public function executeBulk(string $runId, array $objectIds, TriggerType $trigger, array $fingerprints): BulkRunOutcome
    {
        try {
            $report = $this->runBulkOrganize($runId, $objectIds, $trigger, $fingerprints);
            $status = $this->runs->finish($runId);
            if (!$this->reportsSelfFinalized($status)) {
                return BulkRunOutcome::ownershipLost(new LostRunItemOwnershipException(sprintf(
                    'The bulk run did not finalize under this attempt (status %s); another attempt or a reconciler owns it.',
                    $status->value,
                )));
            }

            return BulkRunOutcome::completed($report);
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
    }

    /**
     * The loop-safe bulk organize invocation (run-item lease claim/heartbeat/completion) shared by the
     * controller sync path and the reviewed-selection path; callers own start/finish/failure and mapping.
     *
     * @param list<int>          $objectIds
     * @param array<int, string> $fingerprints
     */
    public function runBulkOrganize(string $runId, array $objectIds, TriggerType $trigger, array $fingerprints): BulkOrganizeReport
    {
        return $this->organizer->organizeBulkDetailed(
            $objectIds,
            $trigger,
            shouldCancel: fn (): bool => $this->runs->isCancellationRequested($runId),
            beforeObject: fn (int $objectId): bool => $this->runItemLease->start($runId, $this->runItemKey($objectId)),
            expectedFingerprints: $fingerprints,
            heartbeat: fn (int $objectId) => $this->runItemLease->pulse($runId, $this->runItemKey($objectId)),
            afterObject: fn (BulkObjectResult $result) => $this->runItemLease->complete(
                $runId,
                $this->runItemKey($result->objectId),
                $this->bulkItemStatus($result->status),
                ['operationCount' => $result->operationCount],
                $result->reason,
            ),
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

    private function reportsSelfFinalized(OperationRunStatus $status): bool
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
            $this->runs->fail($runId, $error);
        }

        return $fenceHeld;
    }

    private function runItemKey(int $objectId): string
    {
        return 'object:' . $objectId;
    }
}
