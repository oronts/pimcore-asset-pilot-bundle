<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Dependency;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

class AssetUploadListener
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly MessageBusInterface $messageBus,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly bool $enabled = true,
        protected readonly bool $asyncEnabled = true,
    ) {}

    public function onAssetPostAdd(AssetEvent $event): void
    {
        $this->handleEvent($event);
    }

    public function onAssetPostUpdate(AssetEvent $event): void
    {
        $this->handleEvent($event);
    }

    protected function handleEvent(AssetEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $asset = $event->getAsset();

        if ($asset instanceof Asset\Folder) {
            return;
        }

        if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly')) {
            return;
        }

        $assetId = (int) $asset->getId();

        // Loop prevention: skip if this asset is currently being moved by the organizer
        if ($this->loopGuard->isProcessingAsset($assetId)) {
            $this->logger->debug('AssetUploadListener: asset {id} is being processed by organizer, skipping', [
                'id' => $assetId,
            ]);
            return;
        }

        // Prevent async ping-pong: skip if this asset was recently moved by Asset Pilot
        // (shared assets between multiple objects would otherwise bounce endlessly)
        if ($this->loopGuard->wasAssetRecentlyMoved($assetId)) {
            $this->logger->debug('AssetUploadListener: asset {id} was recently moved by Asset Pilot, skipping', [
                'id' => $assetId,
            ]);
            return;
        }

        // Find all objects that reference this asset via Pimcore's dependency system
        $dependency = Dependency::getBySourceId($assetId, 'asset');
        $requiredBy = $dependency->getRequiredBy();

        if (empty($requiredBy)) {
            $this->logger->debug('AssetUploadListener: no objects reference asset {id}', [
                'id' => $assetId,
            ]);
            return;
        }

        $this->logger->info('AssetUploadListener: asset {id} referenced by {count} dependencies', [
            'id' => $assetId,
            'count' => count($requiredBy),
        ]);

        foreach ($requiredBy as $dep) {
            if (($dep['type'] ?? '') !== 'object') {
                continue;
            }

            $objectId = (int) ($dep['id'] ?? 0);
            if ($objectId <= 0) {
                continue;
            }

            // Check if the object is already being processed (loop prevention)
            if ($this->loopGuard->isProcessingObject($objectId)) {
                $this->logger->debug('AssetUploadListener: object {id} already being processed, skipping', [
                    'id' => $objectId,
                ]);
                continue;
            }

            if ($this->asyncEnabled) {
                // Dispatch deduplication: skip if a message was recently dispatched for this object
                if ($this->loopGuard->wasObjectRecentlyDispatched($objectId)) {
                    $this->logger->debug('AssetUploadListener: message recently dispatched for object {id}, skipping duplicate', [
                        'id' => $objectId,
                    ]);
                    continue;
                }

                $this->messageBus->dispatch(Envelope::wrap(
                    new OrganizeAssetsMessage(
                        objectId: $objectId,
                        triggerType: TriggerType::AssetUpload,
                        dispatchedAt: time(),
                    ),
                    [new DeduplicateStamp('asset_pilot_organize_' . $objectId, 30.0)]
                ));
                $this->loopGuard->markObjectDispatched($objectId);

                $this->logger->debug('AssetUploadListener: dispatched async organize for object {id}', [
                    'id' => $objectId,
                ]);
            } else {
                try {
                    $object = Concrete::getById($objectId);
                    if ($object === null) {
                        continue;
                    }

                    $this->organizer->organize($object, TriggerType::AssetUpload);

                    $this->logger->debug('AssetUploadListener: sync organize complete for object {id}', [
                        'id' => $objectId,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('AssetUploadListener: failed to organize object {id}: {error}', [
                        'id' => $objectId,
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
            }
        }
    }
}
