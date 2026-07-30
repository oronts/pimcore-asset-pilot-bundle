<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\AssetFilter;
use Oronts\AssetPilotBundle\Service\Query\BoundedScan;
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
        protected readonly int $maxCandidates = 5000,
    ) {}

    /**
     * @return array{assetCount: int, objectIds: list<int>, truncated: bool}
     */
    public function selectFolder(string $folderPath, int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : $this->defaultLimit;
        ['ids' => $assetIds, 'truncated' => $truncated] = $this->listAssetIdsInFolder($folderPath, $limit);

        return ['assetCount' => count($assetIds), 'objectIds' => $this->ownerIdsFor($assetIds), 'truncated' => $truncated];
    }

    /**
     * @param list<int> $assetIds
     *
     * @return array{assetCount: int, objectIds: list<int>, truncated: bool}
     */
    public function selectAssets(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter(
            array_map('intval', $assetIds),
            static fn (int $id): bool => $id > 0,
        )));
        $assetIds = array_values(array_filter($assetIds, fn (int $id): bool => $this->isAssetVisible($id)));

        return ['assetCount' => count($assetIds), 'objectIds' => $this->ownerIdsFor($assetIds), 'truncated' => false];
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
     * The raw scan is bounded by {@see $maxCandidates} so a mostly-hidden folder cannot force a
     * full-folder walk for a workspace-restricted user; truncated is reported when the budget is hit.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    protected function listAssetIdsInFolder(string $folderPath, int $limit): array
    {
        $visible = [];

        $truncated = BoundedScan::run(
            fn (int $offset, int $batch): array => $this->rawAssetIdsInFolder($folderPath, $offset, $batch),
            function (int $id) use (&$visible, $limit): bool {
                if ($this->isAssetVisible($id)) {
                    $visible[] = $id;
                }

                return count($visible) >= $limit;
            },
            $this->maxCandidates,
            min(500, max(50, $limit)),
        );

        return ['ids' => $visible, 'truncated' => $truncated];
    }

    /** @return list<int> */
    protected function rawAssetIdsInFolder(string $folderPath, int $offset, int $limit): array
    {
        // LIKE-escapes the folder path (so a name with `_`/`%` cannot over-match) and excludes folder rows.
        [$condition, $params] = AssetFilter::condition(['folder' => $folderPath], excludeFolders: true);
        $listing = new Asset\Listing();
        $listing->setCondition($condition, $params);
        $listing->setOffset($offset);
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function isAssetVisible(int $assetId): bool
    {
        $asset = Asset::getById($assetId);

        return $asset instanceof Asset
            && !$asset instanceof Asset\Folder
            && $this->authorization->isAllowed($asset, 'view');
    }
}
