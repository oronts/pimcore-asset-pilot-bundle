<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
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
        $this->loopGuard->markObjectDirty($objectId);
        $this->logger->debug('DataObjectSaveListener: {reason} for {class}:{id}, deferring', [
            'reason' => $reason,
            'class' => $className,
            'id' => $objectId,
        ]);
    }

    private function dispatchAsync(int $objectId, string $className, TriggerType $triggerType): void
    {
        if ($this->loopGuard->wasObjectRecentlyDispatched($objectId)) {
            $this->deferObject($objectId, $className, 'message was recently dispatched');

            return;
        }

        try {
            // Record the organize intent as a pending-dispatch run in the ambient transaction; the relay
            // publishes it after commit. A failure here must not fail the already-committed save.
            $this->dispatcher->deferObject($objectId, $triggerType);
        } catch (\Throwable $e) {
            $this->logger->error('DataObjectSaveListener: failed to record async organize for {class}:{id}: {error}', [
                'class' => $className,
                'id' => $objectId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return;
        }
        // Mark dispatched only once the run has committed (nesting 0); a rolled-back save must leave no stale dedup marker.
        if ($this->connection->getTransactionNestingLevel() === 0) {
            $this->loopGuard->markObjectDispatched($objectId);
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
