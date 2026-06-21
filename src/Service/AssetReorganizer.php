<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ReorganizeResult;
use Oronts\AssetPilotBundle\Service\Query\AssetFilter;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

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
        protected readonly OrganizeDispatcher $dispatcher,
        protected readonly LoggerInterface $logger,
        protected readonly int $defaultLimit = 100,
    ) {}

    public function reorganizeFolder(string $folderPath, int $limit = 0, bool $async = false): ReorganizeResult
    {
        $limit = $limit > 0 ? $limit : $this->defaultLimit;
        $assetIds = $this->listAssetIdsInFolder($folderPath, $limit);

        return $this->reorganizeOwnersOf($assetIds, $async);
    }

    /**
     * Re-organize the owners of an explicit set of asset ids (the targeted counterpart to
     * reorganizeFolder, for "fix just these imported assets" rather than a whole staging folder).
     *
     * @param int[] $assetIds
     */
    public function reorganizeAssets(array $assetIds, bool $async = false): ReorganizeResult
    {
        $assetIds = array_values(array_unique(array_filter(
            array_map('intval', $assetIds),
            static fn (int $id): bool => $id > 0,
        )));

        return $this->reorganizeOwnersOf($assetIds, $async);
    }

    /**
     * @param list<int> $assetIds
     */
    private function reorganizeOwnersOf(array $assetIds, bool $async): ReorganizeResult
    {
        $ownerIds = $this->ownerIdsFor($assetIds);
        $counts = $this->organizeOwners($ownerIds, $async);

        return new ReorganizeResult(count($assetIds), count($ownerIds), $counts['organized'], $counts['dispatched'], $counts['skipped'], $counts['failed']);
    }

    /**
     * @param list<int> $assetIds
     * @return list<int> the distinct object ids that own any of the assets
     */
    private function ownerIdsFor(array $assetIds): array
    {
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

        return $ownerIds;
    }

    /**
     * @param list<int> $ownerIds
     * @return array{organized: int, dispatched: int, skipped: int, failed: int}
     */
    private function organizeOwners(array $ownerIds, bool $async): array
    {
        $organized = 0;
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ownerIds as $objectId) {
            if ($async) {
                try {
                    $this->dispatch($objectId);
                    ++$dispatched;
                } catch (\Throwable $e) {
                    $this->logger->error('Asset Pilot: reorganize dispatch failed for object {id}: {error}', [
                        'id' => $objectId,
                        'error' => $e->getMessage(),
                    ]);
                    ++$failed;
                }
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

        return ['organized' => $organized, 'dispatched' => $dispatched, 'skipped' => $skipped, 'failed' => $failed];
    }

    protected function dispatch(int $objectId): void
    {
        $this->dispatcher->dispatchObject($objectId, TriggerType::Manual);
    }

    /**
     * @return list<int>
     */
    protected function listAssetIdsInFolder(string $folderPath, int $limit): array
    {
        // Shared filter helper: LIKE-escapes the folder path (so a name with `_`/`%` cannot
        // over-match) and excludes folder rows, which carry no dependents to reorganize.
        [$condition, $params] = AssetFilter::condition(['folder' => $folderPath], excludeFolders: true);

        $listing = new Asset\Listing();
        $listing->setCondition($condition, $params);
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }
}
