<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ReorganizeResult;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Asset-centric reorganize for post-import: assets land in a staging folder, and re-organizing the
 * objects that own them relocates each asset to its rule-derived path. Resolves owner objects via
 * the dependency resolver and re-organizes them (idempotent), bounded and paged so a large folder
 * never blocks; async queues one organize message per owner instead.
 */
class AssetReorganizer
{
    public function __construct(
        protected readonly AssetDependencyResolver $dependencyResolver,
        protected readonly AssetOrganizer $organizer,
        protected readonly MessageBusInterface $messageBus,
        protected readonly LoggerInterface $logger,
        protected readonly int $defaultLimit = 100,
    ) {}

    public function reorganizeFolder(string $folderPath, int $limit = 0, bool $async = false): ReorganizeResult
    {
        $limit = $limit > 0 ? $limit : $this->defaultLimit;
        $assetIds = $this->listAssetIdsInFolder($folderPath, $limit);

        $ownerIds = [];
        $seen = [];
        foreach ($assetIds as $assetId) {
            foreach ($this->dependencyResolver->dependentObjectIds((int) $assetId) as $objectId) {
                if (!isset($seen[$objectId])) {
                    $seen[$objectId] = true;
                    $ownerIds[] = $objectId;
                }
            }
        }

        $organized = 0;
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ownerIds as $objectId) {
            if ($async) {
                $this->dispatch($objectId);
                ++$dispatched;
                continue;
            }

            $object = $this->loadObject($objectId);
            if ($object === null) {
                ++$skipped;
                continue;
            }

            try {
                $results = $this->organizer->organize($object, TriggerType::Manual);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: reorganize failed for object {id}: {error}', [
                    'id' => $objectId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                ++$failed;
                continue;
            }

            $objectFailed = false;
            foreach ($results as $result) {
                if ($result->status === OperationStatus::Failed) {
                    $objectFailed = true;
                    break;
                }
            }
            $objectFailed ? ++$failed : ++$organized;
        }

        return new ReorganizeResult(count($assetIds), count($ownerIds), $organized, $dispatched, $skipped, $failed);
    }

    protected function dispatch(int $objectId): void
    {
        $this->messageBus->dispatch(Envelope::wrap(
            new OrganizeAssetsMessage($objectId, TriggerType::Manual, time()),
            [new DeduplicateStamp('asset_pilot_organize_' . $objectId, 30.0)],
        ));
    }

    /**
     * @return list<int>
     */
    protected function listAssetIdsInFolder(string $folderPath, int $limit): array
    {
        $listing = new Asset\Listing();
        $listing->setCondition('path LIKE ?', [rtrim($folderPath, '/') . '/%']);
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }
}
