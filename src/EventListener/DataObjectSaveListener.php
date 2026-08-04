<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStoreInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;

class DataObjectSaveListener
{
    public function __construct(
        protected readonly AssetOrganizerInterface $organizer,
        protected readonly OrganizeDispatcherInterface $dispatcher,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly Connection $connection,
        protected readonly AutomaticOrganizeIntentStoreInterface $intents,
        protected readonly bool $enabled = true,
        protected readonly array $allowedClasses = [],
        protected readonly bool $asyncEnabled = true,
    ) {}

    public function onPostUpdate(DataObjectEvent $event): void
    {
        $this->handleEvent($event, TriggerType::ObjectSave);
    }

    public function onPostAdd(DataObjectEvent $event): void
    {
        $this->handleEvent($event, TriggerType::ObjectSave);
    }

    protected function handleEvent(DataObjectEvent $event, TriggerType $triggerType): void
    {
        if ($this->shouldIgnore($event)) {
            return;
        }

        $object = $event->getObject();
        if (!$object instanceof Concrete) {
            return;
        }

        $className = $object->getClassName();
        if (!$this->classIsAllowed($className)) {
            $this->logger->debug('DataObjectSaveListener: class {class} not in allowed list, skipping', [
                'class' => $className,
            ]);

            return;
        }

        $objectId = (int) $object->getId();
        if ($this->loopGuard->isProcessingObject($objectId)) {
            $this->deferObject($objectId, $className, 'object is being processed');

            return;
        }

        $this->logger->debug('DataObjectSaveListener: handling {trigger} for {class}:{id}', [
            'trigger' => $triggerType->value,
            'class' => $className,
            'id' => $objectId,
        ]);

        if ($this->asyncEnabled) {
            $this->dispatchAsync($objectId, $className, $triggerType);

            return;
        }

        $this->organizeSynchronously($object, $objectId, $className, $triggerType);
    }

    private function shouldIgnore(DataObjectEvent $event): bool
    {
        return !$this->enabled
            || ($event->hasArgument('saveVersionOnly') && (bool) $event->getArgument('saveVersionOnly'))
            || ($event->hasArgument('isAutoSave') && (bool) $event->getArgument('isAutoSave'));
    }

    private function classIsAllowed(string $className): bool
    {
        return $this->allowedClasses === [] || in_array($className, $this->allowedClasses, true);
    }

    private function deferObject(int $objectId, string $className, string $reason): void
    {
        // A save while this object is being organized: fold it into the live automatic intent durably. A
        // bulk/sync run owns no intent, so fall back to the cache dirty flag that path drains.
        try {
            if (!$this->intents->markDirtyIfPresent($objectId)) {
                $this->loopGuard->markObjectDirty($objectId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('DataObjectSaveListener: failed to record a coalesced save for {class}:{id}: {error}', [
                'class' => $className,
                'id' => $objectId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            if ($this->connection->getTransactionNestingLevel() > 0) {
                throw $e;
            }

            return;
        }
        $this->logger->debug('DataObjectSaveListener: {reason} for {class}:{id}, deferring', [
            'reason' => $reason,
            'class' => $className,
            'id' => $objectId,
        ]);
    }

    private function dispatchAsync(int $objectId, string $className, TriggerType $triggerType): void
    {
        try {
            // The durable intent is the single coalescing record: deferObject folds the save into the live
            // pending run or records a fresh one, so no cache dispatch marker is needed.
            $this->dispatcher->deferObject($objectId, $triggerType);
        } catch (\Throwable $e) {
            $this->logger->error('DataObjectSaveListener: failed to record async organize for {class}:{id}: {error}', [
                'class' => $className,
                'id' => $objectId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            // Inside a caller-owned transaction the save is not yet committed: surface the failure so it rolls
            // back with the source write. After commit (nesting 0) it must stay best-effort and not fail the save.
            if ($this->connection->getTransactionNestingLevel() > 0) {
                throw $e;
            }

            return;
        }
        $this->logger->debug('DataObjectSaveListener: recorded pending async organize intent for {class}:{id}', [
            'class' => $className,
            'id' => $objectId,
        ]);
    }

    private function organizeSynchronously(Concrete $object, int $objectId, string $className, TriggerType $triggerType): void
    {
        try {
            $results = $this->organizer->organize($object, $triggerType);

            $this->logger->debug('DataObjectSaveListener: sync organization complete for {class}:{id}, {count} results', [
                'class' => $className,
                'id' => $objectId,
                'count' => count($results),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('DataObjectSaveListener: failed to organize {class}:{id}: {error}', [
                'class' => $className,
                'id' => $objectId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
