<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\LostRunItemOwnershipException;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

class ReviewedObjectOperationService implements ReviewedObjectOperationServiceInterface
{
    private readonly SynchronousRunExecutor $syncRunExecutor;

    public function __construct(
        private readonly AssetOrganizerInterface $organizer,
        private readonly OrganizeDispatcherInterface $dispatcher,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly ActorContextStore $actors,
        private readonly OperationRunStoreInterface $runs,
        private readonly ApplyPlanServiceInterface $applyPlans,
        private readonly OrganizePlanFingerprint $fingerprints,
        private readonly LoggerInterface $logger,
        private readonly RunItemLease $runItemLease,
        private readonly array $planConfiguration = [],
        ?SynchronousRunExecutor $syncRunExecutor = null,
    ) {
        $this->syncRunExecutor = $syncRunExecutor ?? new SynchronousRunExecutor($this->organizer, $this->runs, $this->runItemLease);
    }

    public function execute(
        OperationRunKind $kind,
        array $objectIds,
        array $selector,
        TriggerType $triggerType,
        bool $dryRun,
        bool $async,
        mixed $planToken = null,
        ?ActorContext $actor = null,
    ): ReviewedSelectionResult {
        if (!$dryRun && (!is_string($planToken) || trim($planToken) === '')) {
            throw new ReviewedSelectionException(
                ReviewedSelectionError::MissingPlanToken,
                'A plan token from a matching preview is required.',
            );
        }

        $actor ??= $this->authorization->currentActor();

        // AssetOrganizer's per-asset checks read the ambient actor; binding the whole flow keeps them from
        // falling back to System while the object checks use $actor.
        return $this->actors->runAs($actor, fn (): ReviewedSelectionResult => $this->executeAs(
            $kind,
            $objectIds,
            $selector,
            $triggerType,
            $dryRun,
            $async,
            $planToken,
            $actor,
        ));
    }

    /**
     * @param list<int>    $objectIds
     * @param array<mixed> $selector
     */
    private function executeAs(
        OperationRunKind $kind,
        array $objectIds,
        array $selector,
        TriggerType $triggerType,
        bool $dryRun,
        bool $async,
        mixed $planToken,
        ActorContext $actor,
    ): ReviewedSelectionResult {
        $objectIds = $this->normalizeObjectIds($objectIds);
        if (count($objectIds) > BulkIds::MAX) {
            throw new ReviewedSelectionException(
                ReviewedSelectionError::SelectionTooLarge,
                sprintf('The selection exceeds the maximum of %d objects.', BulkIds::MAX),
            );
        }

        [$objects, $skipped] = $this->loadEligibleObjects($objectIds, $actor, $dryRun);
        if ($objects === []) {
            if ($dryRun) {
                return new ReviewedSelectionResult(true, null, null, null, 0, 0, 0, $skipped, 0);
            }

            throw new ReviewedSelectionException(
                ReviewedSelectionError::StalePlan,
                'The preview plan is stale or already applied. Run a new preview.',
            );
        }

        $preview = $this->preview($objects, $triggerType);
        $eligibleIds = array_map(static fn (AbstractObject $object): int => (int) $object->getId(), $objects);
        $request = [
            'objectIds' => $eligibleIds,
            'operations' => array_map($this->operationIdentity(...), $preview['operations']),
            'selector' => $selector,
            'trigger' => $triggerType->value,
        ];
        $plan = new ApplyPlan($kind->value, $actor, $request, $this->planConfiguration, $preview['targets']);

        if ($dryRun) {
            return new ReviewedSelectionResult(
                true,
                $this->applyPlans->issue($plan),
                null,
                null,
                count($eligibleIds),
                0,
                0,
                $skipped,
                0,
                $preview['operations'],
            );
        }

        $preflightError = $this->organizer->preflightApply($preview['operations']);
        if ($preflightError !== null) {
            throw new ReviewedSelectionException(ReviewedSelectionError::PreflightFailed, $preflightError);
        }
        $this->claim((string) $planToken, $plan);

        try {
            $runId = $this->dispatcher->createRun(
                $eligibleIds,
                $triggerType,
                $actor,
                $preview['fingerprints'],
                $kind,
                $request,
            );
        } catch (\Throwable $e) {
            throw new ReviewedSelectionException(
                ReviewedSelectionError::ExecutionFailed,
                'The reviewed operation run could not be created.',
                previous: $e,
            );
        }

        if ($async) {
            return $this->dispatch($runId, $eligibleIds, $preview['fingerprints'], $triggerType, $actor, $skipped);
        }

        return $this->executeSynchronously($runId, $eligibleIds, $preview['fingerprints'], $triggerType, $skipped);
    }

    /** @param list<int> $objectIds @return list<int> */
    private function normalizeObjectIds(array $objectIds): array
    {
        $objectIds = array_values(array_unique(array_filter(
            array_map('intval', $objectIds),
            static fn (int $id): bool => $id > 0,
        )));
        sort($objectIds, SORT_NUMERIC);

        return $objectIds;
    }

    /**
     * @param list<int> $objectIds
     * @return array{0: list<AbstractObject>, 1: int}
     */
    private function loadEligibleObjects(array $objectIds, ActorContext $actor, bool $dryRun): array
    {
        $objects = [];
        $skipped = 0;
        foreach ($objectIds as $objectId) {
            $object = $this->loadObject($objectId);
            if ($object === null || !$this->authorization->isAllowed($object, 'view', $actor)) {
                ++$skipped;
                continue;
            }
            if (!$dryRun && !$this->authorization->isAllowed($object, 'publish', $actor)) {
                throw new ReviewedSelectionException(
                    ReviewedSelectionError::MutationForbidden,
                    'Object mutation is not permitted.',
                    $objectId,
                );
            }
            $objects[] = $object;
        }

        return [$objects, $skipped];
    }

    /**
     * @param list<AbstractObject> $objects
     * @return array{operations: list<MoveOperation>, targets: list<ApplyPlanTarget>, fingerprints: array<int, string>}
     */
    private function preview(array $objects, TriggerType $triggerType): array
    {
        $operations = [];
        $targets = [];
        $fingerprints = [];
        foreach ($objects as $object) {
            $objectOperations = $this->organizer->dryRun($object, $triggerType);
            $objectId = (int) $object->getId();
            $fingerprint = $this->fingerprints->forOperations($object, $objectOperations);
            $fingerprints[$objectId] = $fingerprint;
            $targets[] = new ApplyPlanTarget('object:' . $objectId, $fingerprint);
            array_push($operations, ...$objectOperations);
        }

        return ['operations' => $operations, 'targets' => $targets, 'fingerprints' => $fingerprints];
    }

    private function claim(string $token, ApplyPlan $plan): void
    {
        $status = $this->applyPlans->claim($token, $plan);
        if ($status === ApplyPlanStatus::Claimed) {
            return;
        }
        if ($status === ApplyPlanStatus::Malformed) {
            throw new ReviewedSelectionException(
                ReviewedSelectionError::MalformedPlanToken,
                'The plan token is malformed or has an invalid signature.',
            );
        }

        throw new ReviewedSelectionException(
            ReviewedSelectionError::StalePlan,
            'The preview plan is stale or already applied. Run a new preview.',
        );
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $fingerprints
     */
    private function dispatch(
        string $runId,
        array $objectIds,
        array $fingerprints,
        TriggerType $triggerType,
        ActorContext $actor,
        int $selectionSkipped,
    ): ReviewedSelectionResult {
        try {
            $this->dispatcher->dispatchBulk($objectIds, $triggerType, $actor, $runId, $fingerprints);
        } catch (\Throwable $e) {
            $this->runs->fail($runId, 'Reviewed operation dispatch failed.');
            $this->logger->error('Asset Pilot: reviewed operation dispatch failed', ['run_id' => $runId, 'exception' => $e]);

            throw new ReviewedSelectionException(
                ReviewedSelectionError::ExecutionFailed,
                'The reviewed operation could not be queued.',
                runId: $runId,
                previous: $e,
            );
        }

        return new ReviewedSelectionResult(
            false,
            null,
            $runId,
            OperationRunStatus::Queued,
            count($objectIds),
            0,
            count($objectIds),
            $selectionSkipped,
            0,
        );
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $fingerprints
     */
    private function executeSynchronously(
        string $runId,
        array $objectIds,
        array $fingerprints,
        TriggerType $triggerType,
        int $selectionSkipped,
    ): ReviewedSelectionResult {
        if (!$this->runs->start($runId)) {
            $this->runs->fail($runId, 'Reviewed operation run could not be started.');

            throw new ReviewedSelectionException(
                ReviewedSelectionError::ExecutionFailed,
                'The reviewed operation run could not be started.',
                runId: $runId,
            );
        }
        try {
            $report = $this->syncRunExecutor->runBulkOrganize($runId, $objectIds, $triggerType, $fingerprints);
        } catch (LostRunItemOwnershipException $e) {
            throw new ReviewedSelectionException(
                ReviewedSelectionError::OwnershipLost,
                'A run item was reclaimed by a concurrent attempt before it could be recorded.',
                runId: $runId,
                previous: $e,
            );
        } catch (\Throwable $e) {
            if (!$this->syncRunExecutor->failOwnedItemsAndRun($runId, $objectIds, 'Reviewed organization failed.')) {
                throw new ReviewedSelectionException(
                    ReviewedSelectionError::OwnershipLost,
                    'A run item was reclaimed by a concurrent attempt before the reviewed operation could record it.',
                    runId: $runId,
                    previous: $e,
                );
            }
            $this->logger->error('Asset Pilot: reviewed organization failed', ['run_id' => $runId, 'exception' => $e]);

            throw new ReviewedSelectionException(
                ReviewedSelectionError::ExecutionFailed,
                'The reviewed operation failed.',
                runId: $runId,
                previous: $e,
            );
        }

        // Finalize the parent separately so a throwing finish() reports ownership loss, not a failed run.
        try {
            $status = $this->runs->finish($runId);
        } catch (\Throwable $e) {
            throw new ReviewedSelectionException(
                ReviewedSelectionError::OwnershipLost,
                'The reviewed run items completed but parent finalization failed; a reconciler derives the durable status.',
                runId: $runId,
                previous: $e,
            );
        }
        if (!$this->syncRunExecutor->reportsSelfFinalized($status)) {
            throw new ReviewedSelectionException(
                ReviewedSelectionError::OwnershipLost,
                'The reviewed run did not finalize under this attempt; another attempt or a reconciler owns it.',
                runId: $runId,
            );
        }

        return new ReviewedSelectionResult(
            false,
            null,
            $runId,
            $status,
            $report->attemptedCount(),
            $report->succeededCount(),
            0,
            $selectionSkipped + $report->skippedCount(),
            $report->failedCount(),
            objectResults: $report->objectResults,
            observerWarnings: $report->observerWarnings,
        );
    }

    /** @return array<string, int|string|null> */
    private function operationIdentity(MoveOperation $operation): array
    {
        return [
            'assetId' => $operation->assetId,
            'error' => $operation->errorMessage,
            'objectClass' => $operation->objectClass,
            'objectId' => $operation->objectId,
            'ruleName' => $operation->ruleName,
            'sourcePath' => $operation->sourcePath,
            'status' => $operation->status->value,
            'targetPath' => $operation->targetPath,
        ];
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }
}
