<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\OrganizationRunDispatchException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\QueuedOrganizationRun;
use Psr\Log\LoggerInterface;

/**
 * Owns the async organize dispatch saga once a plan is claimed: create the durable run, dispatch its
 * Messenger envelopes, and compensate (fail) any item that could not be queued. Transport-neutral by
 * design, the caller maps {@see QueuedOrganizationRun} and {@see OrganizationRunDispatchException} to
 * its own response contract. Run ownership (the item claim-token lease) is minted later by the worker
 * handlers, never here.
 */
final readonly class OrganizeRunDispatchCoordinator
{
    public function __construct(
        private OrganizeDispatcherInterface $organizeDispatcher,
        private OperationRunStoreInterface $runs,
        private LoggerInterface $logger,
    ) {}

    public function queueOrganization(int $objectId, TriggerType $triggerType, ActorContext $actor, string $expectedFingerprint): QueuedOrganizationRun
    {
        $runId = $this->organizeDispatcher->createRun(
            [$objectId],
            $triggerType,
            $actor,
            [$objectId => $expectedFingerprint],
        );
        try {
            $this->organizeDispatcher->dispatchObject(
                $objectId,
                $triggerType,
                $actor,
                $runId,
                $expectedFingerprint,
            );
        } catch (\Throwable $exception) {
            $this->runs->fail($runId, 'Organization could not be queued.');
            $this->logger->error('Asset Pilot API: organization dispatch failed', [
                'run_id' => $runId,
                'object_id' => $objectId,
                'exception' => $exception,
            ]);

            throw new OrganizationRunDispatchException($runId, 'Organization could not be queued.', $exception);
        }

        return new QueuedOrganizationRun($runId, 1, 1);
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $expectedFingerprints
     */
    public function queueBulkOrganization(array $objectIds, TriggerType $triggerType, ActorContext $actor, array $expectedFingerprints, int $batchSize): QueuedOrganizationRun
    {
        $batches = array_chunk($objectIds, $batchSize);
        $runId = $this->organizeDispatcher->createRun($objectIds, $triggerType, $actor, $expectedFingerprints);
        foreach ($batches as $batchIndex => $batch) {
            try {
                $this->organizeDispatcher->dispatchBulk(
                    $batch,
                    $triggerType,
                    $actor,
                    $runId,
                    array_intersect_key($expectedFingerprints, array_flip($batch)),
                );
            } catch (\Throwable $exception) {
                $this->failUndispatchedBatches($runId, array_slice($batches, $batchIndex));
                $this->logger->error('Asset Pilot API: bulk organization dispatch failed', [
                    'run_id' => $runId,
                    'batch_index' => $batchIndex,
                    'exception' => $exception,
                ]);

                throw new OrganizationRunDispatchException($runId, 'Bulk organization could not be queued.', $exception);
            }
        }

        return new QueuedOrganizationRun($runId, count($objectIds), count($batches));
    }

    /** @param list<list<int>> $batches */
    private function failUndispatchedBatches(string $runId, array $batches): void
    {
        foreach ($batches as $batch) {
            foreach ($batch as $objectId) {
                $this->runs->completeItem(
                    $runId,
                    'object:' . $objectId,
                    OperationRunItemStatus::Failed,
                    error: 'Bulk organization could not be queued.',
                );
            }
        }
        $this->runs->finish($runId);
    }
}
