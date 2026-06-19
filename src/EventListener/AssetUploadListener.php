<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Dependency;
use Psr\Log\LoggerInterface;

class AssetUploadListener
{
    private const int DEPENDENCY_PAGE_SIZE = 100;

    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly OrganizeDispatcher $dispatcher,
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

        // Find all objects that reference this asset via Pimcore's dependency system.
        $dependency = Dependency::getBySourceId($assetId, 'asset');
        $processed = $this->dispatchForDependents($dependency);

        if ($processed === 0) {
            $this->logger->debug('AssetUploadListener: no objects reference asset {id}', ['id' => $assetId]);

            return;
        }

        $this->logger->info('AssetUploadListener: asset {id} referenced by {count} dependencies', [
            'id' => $assetId,
            'count' => $processed,
        ]);
    }

    /**
     * Page the reverse dependencies so a widely-shared asset never loads every referrer into memory.
     *
     * @return int the number of dependency rows processed
     */
    protected function dispatchForDependents(Dependency $dependency): int
    {
        $offset = 0;
        $processed = 0;
        do {
            $chunk = $this->fetchDependents($dependency, $offset, self::DEPENDENCY_PAGE_SIZE);
            foreach ($chunk as $dep) {
                $this->processDependent($dep);
            }
            $processed += count($chunk);
            $offset += self::DEPENDENCY_PAGE_SIZE;
        } while (count($chunk) === self::DEPENDENCY_PAGE_SIZE);

        return $processed;
    }

    /** @return array<int, array{type?: string, id?: int|string}> */
    protected function fetchDependents(Dependency $dependency, int $offset, int $limit): array
    {
        return $dependency->getRequiredBy($offset, $limit);
    }

    /** @param array{type?: string, id?: int|string} $dep a Pimcore reverse-dependency row */
    protected function processDependent(array $dep): void
    {
        if (($dep['type'] ?? '') !== 'object') {
            return;
        }

        $objectId = (int) ($dep['id'] ?? 0);
        if ($objectId <= 0) {
            return;
        }

        // Check if the object is already being processed (loop prevention)
        if ($this->loopGuard->isProcessingObject($objectId)) {
            $this->logger->debug('AssetUploadListener: object {id} already being processed, skipping', [
                'id' => $objectId,
            ]);
            return;
        }

        if ($this->asyncEnabled) {
            // Dispatch deduplication: skip if a message was recently dispatched for this object
            if ($this->loopGuard->wasObjectRecentlyDispatched($objectId)) {
                $this->logger->debug('AssetUploadListener: message recently dispatched for object {id}, skipping duplicate', [
                    'id' => $objectId,
                ]);
                return;
            }

            $this->dispatcher->dispatchObject($objectId, TriggerType::AssetUpload);
            $this->loopGuard->markObjectDispatched($objectId);

            $this->logger->debug('AssetUploadListener: dispatched async organize for object {id}', [
                'id' => $objectId,
            ]);

            return;
        }

        try {
            $object = Concrete::getById($objectId);
            if ($object === null) {
                return;
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
