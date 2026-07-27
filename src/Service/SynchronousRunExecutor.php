<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
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
            $this->runs->finish($runId);

            return SingleRunOutcome::completed($results);
        } catch (StaleApplyPlanException) {
            if ($this->runItemLease->complete($runId, $itemKey, OperationRunItemStatus::Skipped, error: 'Object changed after preview; the immutable plan was not applied.')) {
                $this->runs->finish($runId);
            }

            return SingleRunOutcome::stale();
        } catch (\Throwable $e) {
            if ($this->runItemLease->complete($runId, $itemKey, OperationRunItemStatus::Failed, error: 'Organization failed.')) {
                $this->runs->fail($runId, 'Organization failed.');
            }

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
            $this->runs->finish($runId);

            return BulkRunOutcome::completed($report);
        } catch (LostRunItemOwnershipException $e) {
            return BulkRunOutcome::ownershipLost($e);
        } catch (\Throwable $e) {
            foreach ($objectIds as $objectId) {
                $this->runItemLease->complete($runId, $this->runItemKey($objectId), OperationRunItemStatus::Failed, error: 'Bulk organization failed.');
            }
            $this->runs->fail($runId, 'Bulk organization failed.');

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

    private function runItemKey(int $objectId): string
    {
        return 'object:' . $objectId;
    }
}
