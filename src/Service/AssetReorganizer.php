<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\AssetFilter;
use Pimcore\Model\Asset;

/**
 * Resolves the exact owner-object selection for assets in a folder or an explicit asset set.
 */
class AssetReorganizer implements AssetReorganizerInterface
{
    public function __construct(
        protected readonly AssetDependencyResolverInterface $dependencyResolver,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly int $defaultLimit = 100,
    ) {}

    /**
     * @return array{assetCount: int, objectIds: list<int>}
     */
    public function selectFolder(string $folderPath, int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : $this->defaultLimit;
        $assetIds = $this->listAssetIdsInFolder($folderPath, $limit);

        return ['assetCount' => count($assetIds), 'objectIds' => $this->ownerIdsFor($assetIds)];
    }

    /**
     * @param list<int> $assetIds
     *
     * @return array{assetCount: int, objectIds: list<int>}
     */
    public function selectAssets(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter(
            array_map('intval', $assetIds),
            static fn (int $id): bool => $id > 0,
        )));
        $assetIds = array_values(array_filter($assetIds, fn (int $id): bool => $this->isAssetVisible($id)));

        return ['assetCount' => count($assetIds), 'objectIds' => $this->ownerIdsFor($assetIds)];
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
     * @return list<int>
     */
    protected function listAssetIdsInFolder(string $folderPath, int $limit): array
    {
        // Shared filter helper: LIKE-escapes the folder path (so a name with `_`/`%` cannot
        // over-match) and excludes folder rows, which carry no dependents to reorganize.
        [$condition, $params] = AssetFilter::condition(['folder' => $folderPath], excludeFolders: true);

        $visible = [];
        $offset = 0;
        $batchSize = min(500, max(50, $limit));
        do {
            $listing = new Asset\Listing();
            $listing->setCondition($condition, $params);
            $listing->setOffset($offset);
            $listing->setLimit($batchSize);
            $ids = array_map('intval', $listing->loadIdList());

            foreach ($ids as $id) {
                if ($this->isAssetVisible($id)) {
                    $visible[] = $id;
                    if (count($visible) >= $limit) {
                        break 2;
                    }
                }
            }
            $offset += $batchSize;
        } while (count($ids) === $batchSize);

        return $visible;
    }

    protected function isAssetVisible(int $assetId): bool
    {
        $asset = Asset::getById($assetId);

        return $asset instanceof Asset
            && !$asset instanceof Asset\Folder
            && $this->authorization->isAllowed($asset, 'view');
    }
}
