<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Pimcore\Model\Asset;

interface AssetPropertyServiceInterface
{
    /** @return list<string> */
    public function lockAsset(int $assetId): array;

    /** @return list<string> */
    public function unlockAsset(int $assetId): array;

    public function setProperty(int $assetId, string $name, string $type, string $data): void;

    public function setPropertyOnLockedAsset(Asset $asset, string $name, string $type, string $data): void;

    /**
     * @param list<Asset> $assets
     * @return array{updated: int, failed: int, errors: array<int|string, string>, observerWarnings: list<string>}
     */
    public function bulkSetPropertyOnLockedAssets(array $assets, string $name, string $type, string|bool $value): array;
}
