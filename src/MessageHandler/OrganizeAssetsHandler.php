<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class OrganizeAssetsHandler
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly LoggerInterface $logger,
    ) {}

    public function __invoke(OrganizeAssetsMessage $message): void
    {
        $object = AbstractObject::getById($message->objectId);

        if ($object === null) {
            $this->logger->warning('Asset Pilot: object {id} not found for async organization', [
                'id' => $message->objectId,
            ]);
            return;
        }

        // Stale job detection: skip if the object was modified after this message was dispatched
        if ($message->dispatchedAt > 0 && $object instanceof Concrete) {
            $modifiedAt = $object->getModificationDate();
            if ($modifiedAt > $message->dispatchedAt) {
                $this->logger->info('Asset Pilot: skipping stale message for object {id} (dispatched: {dispatched}, modified: {modified})', [
                    'id' => $message->objectId,
                    'dispatched' => date('Y-m-d H:i:s', $message->dispatchedAt),
                    'modified' => date('Y-m-d H:i:s', $modifiedAt),
                ]);
                return;
            }
        }

        $this->logger->info('Asset Pilot: processing async organization for object {id} (trigger: {trigger})', [
            'id' => $message->objectId,
            'trigger' => $message->triggerType->value,
        ]);

        try {
            $results = $this->organizer->organize($object, $message->triggerType);

            $this->logger->info('Asset Pilot: async organization complete for object {id} - {count} operations', [
                'id' => $message->objectId,
                'count' => count($results),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: async organization failed for object {id}: {error}', [
                'id' => $message->objectId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
