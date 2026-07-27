<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface AssetReorganizerInterface
{
    /** @return array{assetCount: int, objectIds: list<int>} */
    public function selectFolder(string $folderPath, int $limit = 0): array;

    /** @param list<int> $assetIds @return array{assetCount: int, objectIds: list<int>} */
    public function selectAssets(array $assetIds): array;
}
