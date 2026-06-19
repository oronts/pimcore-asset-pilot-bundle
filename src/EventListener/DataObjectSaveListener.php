<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;

class DataObjectSaveListener
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly OrganizeDispatcher $dispatcher,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
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
        if (!$this->enabled) {
            return;
        }

        $object = $event->getObject();

        if (!$object instanceof Concrete) {
            return;
        }

        $className = $object->getClassName();

        if ($this->allowedClasses !== [] && !in_array($className, $this->allowedClasses, true)) {
            $this->logger->debug('DataObjectSaveListener: class {class} not in allowed list, skipping', [
                'class' => $className,
            ]);

            return;
        }

        $objectId = (int) $object->getId();

        // Loop prevention via Redis: skip if this object is currently being organized
        if ($this->loopGuard->isProcessingObject($objectId)) {
            $this->logger->debug('DataObjectSaveListener: object {class}:{id} is being processed, skipping', [
                'class' => $className,
                'id' => $objectId,
            ]);
            return;
        }

        $this->logger->debug('DataObjectSaveListener: handling {trigger} for {class}:{id}', [
            'trigger' => $triggerType->value,
            'class' => $className,
            'id' => $objectId,
        ]);

        if ($this->asyncEnabled) {
            // Dispatch deduplication: skip if a message was recently dispatched for this object
            if ($this->loopGuard->wasObjectRecentlyDispatched($objectId)) {
                $this->logger->debug('DataObjectSaveListener: message recently dispatched for {class}:{id}, skipping duplicate', [
                    'class' => $className,
                    'id' => $objectId,
                ]);
                return;
            }

            $this->dispatcher->dispatchObject($objectId, $triggerType);
            $this->loopGuard->markObjectDispatched($objectId);

            $this->logger->debug('DataObjectSaveListener: dispatched async message for {class}:{id}', [
                'class' => $className,
                'id' => $objectId,
            ]);

            return;
        }

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
