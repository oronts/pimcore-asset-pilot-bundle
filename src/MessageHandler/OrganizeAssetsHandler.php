<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\LostRunItemOwnershipException;
use Oronts\AssetPilotBundle\Exception\RetryableDispatchException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\RetryableInfrastructureFailure;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
class OrganizeAssetsHandler
{
    use PulsesRunItemLease;
    public function __construct(
        protected readonly AssetOrganizerInterface $organizer,
        protected readonly OrganizeDispatcherInterface $dispatcher,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly ActorContextStore $actors,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly OperationRunStoreInterface $runs,
        protected readonly OrganizePlanFingerprint $planFingerprints,
    ) {}

    public function __invoke(OrganizeAssetsMessage $message): void
    {
        if ($message->runId === null) {
            $this->process($message);

            return;
        }

        $itemKey = $this->itemKey($message->objectId);
        if (!$this->loopGuard->acquireOperationRunItem($message->runId, $itemKey)) {
            throw new RecoverableMessageHandlingException('The operation run item is already being processed.');
        }

        try {
            $this->process($message);
        } finally {
            $this->loopGuard->releaseOperationRunItem($message->runId, $itemKey);
        }
    }

    private function process(OrganizeAssetsMessage $message): void
    {
        if (!$this->startRun($message)) {
            return;
        }
        if ($message->runId !== null && $this->runs->isCancellationRequested($message->runId)) {
            $this->completeRunItem($message, OperationRunItemStatus::Cancelled, 'Cancellation was requested before processing.');
            return;
        }

        $object = $this->loadObject($message->objectId);

        if ($object === null) {
            $this->completeMissingObject($message);
            return;
        }

        $actor = new ActorContext($message->actorType, $message->actorUserId);
        if (!$this->actorCanOrganize($message, $object, $actor)) {
            return;
        }

        if (!$this->expectedPlanIsCurrent($message, $object, $actor)) {
            $this->completeStalePlan($message);
            return;
        }

        if ($this->skipStaleMessage($message, $object, $actor)) {
            return;
        }

        $this->organizeObject($message, $object, $actor);
    }

    private function completeMissingObject(OrganizeAssetsMessage $message): void
    {
        $this->logger->warning('Asset Pilot: object {id} not found for async organization', [
            'id' => $message->objectId,
        ]);
        $this->completeRunItem($message, OperationRunItemStatus::Failed, 'Object no longer exists.');
    }

    private function actorCanOrganize(OrganizeAssetsMessage $message, AbstractObject $object, ActorContext $actor): bool
    {
        if ($this->authorization->isAllowed($object, 'view', $actor) && $this->authorization->isAllowed($object, 'publish', $actor)) {
            return true;
        }

        $this->logger->warning('Asset Pilot: actor is not permitted to organize object {id}', [
            'id' => $message->objectId,
            'actor_type' => $actor->type->value,
            'actor_user_id' => $actor->userId,
        ]);
        $this->completeRunItem($message, OperationRunItemStatus::Failed, 'The initiating actor is no longer permitted to publish this object.');

        return false;
    }

    private function expectedPlanIsCurrent(OrganizeAssetsMessage $message, AbstractObject $object, ActorContext $actor): bool
    {
        if ($message->expectedFingerprint === null) {
            return true;
        }

        return $this->actors->runAs(
            $actor,
            function () use ($message, $object): bool {
                $operations = $this->organizer->dryRun($object, TriggerType::Api);

                return hash_equals($message->expectedFingerprint, $this->planFingerprints->forOperations($object, $operations));
            },
        );
    }

    private function completeStalePlan(OrganizeAssetsMessage $message): void
    {
        $this->logger->info('Asset Pilot: skipping object {id} because it changed after immutable preview', [
            'id' => $message->objectId,
        ]);
        $this->completeRunItem($message, OperationRunItemStatus::Skipped, 'Object changed after preview; the immutable plan was not applied.');
    }

    private function skipStaleMessage(OrganizeAssetsMessage $message, AbstractObject $object, ActorContext $actor): bool
    {
        if ($message->expectedFingerprint !== null || $message->dispatchedAt <= 0 || !$object instanceof Concrete) {
            return false;
        }

        $modifiedAt = $object->getModificationDate();
        if ($modifiedAt <= $message->dispatchedAt) {
            return false;
        }

        $this->logger->info('Asset Pilot: skipping stale message for object {id} (dispatched: {dispatched}, modified: {modified})', [
            'id' => $message->objectId,
            'dispatched' => date('Y-m-d H:i:s', $message->dispatchedAt),
            'modified' => date('Y-m-d H:i:s', $modifiedAt),
        ]);
        try {
            $this->dispatcher->dispatchObject($message->objectId, $message->triggerType, $actor);
        } catch (\Throwable $exception) {
            throw new RetryableDispatchException('The latest object state could not be queued.', previous: $exception);
        }

        $this->completeRunItem($message, OperationRunItemStatus::Skipped, 'Object changed after this run was dispatched.');

        return true;
    }

    private function organizeObject(OrganizeAssetsMessage $message, AbstractObject $object, ActorContext $actor): void
    {
        $this->logger->info('Asset Pilot: processing async organization for object {id} (trigger: {trigger})', [
            'id' => $message->objectId,
            'trigger' => $message->triggerType->value,
        ]);

        try {
            $processedModificationDate = $object instanceof Concrete ? $object->getModificationDate() : null;
            $results = $this->executeOrganization($message, $object, $actor);
            $this->logger->info('Asset Pilot: async organization complete for object {id} - {count} operations', [
                'id' => $message->objectId,
                'count' => count($results),
            ]);
            $this->requeueWhenObjectChanged($message, $actor, $processedModificationDate);
            $this->completeRunItem($message, $this->itemStatus($results), null, ['operationCount' => count($results)]);
        } catch (StaleApplyPlanException) {
            $this->completeStalePlan($message);
        } catch (\Throwable $e) {
            if ($e instanceof LostRunItemOwnershipException || RetryableInfrastructureFailure::matches($e)) {
                throw $e;
            }
            $this->failOrganization($message, $e);
        }
    }

    /** @return list<\Oronts\AssetPilotBundle\Model\OperationResult> */
    private function executeOrganization(OrganizeAssetsMessage $message, AbstractObject $object, ActorContext $actor): array
    {
        return $this->actors->runAs(
            $actor,
            fn (): array => $message->runId === null
                ? $this->organizer->organize(
                    $object,
                    $message->triggerType,
                    expectedFingerprint: $message->expectedFingerprint,
                )
                : $this->organizer->organizeWithHeartbeat(
                    $object,
                    $message->triggerType,
                    fn () => $this->pulseRunItemLease($message->runId, $this->itemKey($message->objectId)),
                    expectedFingerprint: $message->expectedFingerprint,
                ),
        );
    }

    private function failOrganization(OrganizeAssetsMessage $message, \Throwable $exception): void
    {
        $this->logger->error('Asset Pilot: async organization failed for object {id}: {error}', [
            'id' => $message->objectId,
            'error' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        if ($message->runId !== null) {
            $itemKey = $this->itemKey($message->objectId);
            $owned = $this->runs->completeItem(
                $message->runId,
                $itemKey,
                OperationRunItemStatus::Failed,
                error: 'Async organization failed.',
                token: $this->loopGuard->operationRunItemToken($message->runId, $itemKey),
            );
            if ($owned) {
                $this->runs->fail($message->runId, 'Async organization failed.');
            }

            return;
        }

        throw $exception;
    }

    protected function loadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId);
    }

    protected function reloadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId, ['force' => true]);
    }

    private function requeueWhenObjectChanged(OrganizeAssetsMessage $message, ActorContext $actor, ?int $processedModificationDate): void
    {
        if ($processedModificationDate === null || $message->expectedFingerprint !== null) {
            return;
        }

        $current = $this->reloadObject($message->objectId);
        $wasSavedDuringProcessing = $this->loopGuard->isObjectDirty($message->objectId);
        if (!$wasSavedDuringProcessing && (!$current instanceof Concrete || $current->getModificationDate() <= $processedModificationDate)) {
            return;
        }

        $this->loopGuard->markObjectDirty($message->objectId);
        $this->logger->info('Asset Pilot: object {id} changed during organization; queueing its latest state', [
            'id' => $message->objectId,
        ]);
        try {
            $this->dispatcher->dispatchObject($message->objectId, $message->triggerType, $actor);
        } catch (\Throwable $exception) {
            throw new RetryableDispatchException('The latest object state could not be queued.', previous: $exception);
        }
        $this->loopGuard->clearObjectDirty($message->objectId);
    }

    private function startRun(OrganizeAssetsMessage $message): bool
    {
        if ($message->runId === null) {
            return true;
        }

        if ($this->runs->isCancellationRequested($message->runId)) {
            $this->completeRunItem($message, OperationRunItemStatus::Cancelled, 'Cancellation was requested before processing.');

            return false;
        }
        if (!$this->runs->resume($message->runId)) {
            return false;
        }
        if ($this->runs->isCancellationRequested($message->runId)) {
            $this->completeRunItem($message, OperationRunItemStatus::Cancelled, 'Cancellation was requested before processing.');

            return false;
        }

        $itemKey = $this->itemKey($message->objectId);

        return $this->runs->resumeItem($message->runId, $itemKey, $this->loopGuard->beginOperationRunItemLease($message->runId, $itemKey));
    }

    /** @param list<\Oronts\AssetPilotBundle\Model\OperationResult> $results */
    private function itemStatus(array $results): OperationRunItemStatus
    {
        if ($results === []) {
            return OperationRunItemStatus::Skipped;
        }
        if (array_any($results, static fn ($result): bool => $result->status === OperationStatus::Failed)) {
            return OperationRunItemStatus::Failed;
        }
        if (array_all($results, static fn ($result): bool => $result->status === OperationStatus::Skipped)) {
            return OperationRunItemStatus::Skipped;
        }

        return OperationRunItemStatus::Completed;
    }

    /** @param array<string, mixed> $result */
    private function completeRunItem(
        OrganizeAssetsMessage $message,
        OperationRunItemStatus $status,
        ?string $error = null,
        array $result = [],
    ): void {
        if ($message->runId === null) {
            return;
        }

        $itemKey = $this->itemKey($message->objectId);
        if (!$this->runs->completeItem($message->runId, $itemKey, $status, $result, $error, $this->loopGuard->operationRunItemToken($message->runId, $itemKey))) {
            throw LostRunItemOwnershipException::forItem($message->runId, $itemKey);
        }
        $this->runs->finish($message->runId);
    }

    /**
     * Renew both the Symfony item lock and the durable database lease in one heartbeat, aborting the run
     * if either was lost (the item was reclaimed by a redelivery or reconciled as abandoned). Fires often
     * enough — around each asset save — that a legitimately long item never lets its lease expire.
     */
}
