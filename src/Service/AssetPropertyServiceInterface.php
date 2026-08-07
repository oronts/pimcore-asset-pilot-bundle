<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Pimcore\Model\Asset;

/**
 * Trusted in-process asset-property mutation seam. The mutation shapes carry different preconditions:
 *
 * - `lockAsset()` / `unlockAsset()` toggle the business protection property and dispatch events;
 *   `lockAsset()` sets it via `setProperty()`, `unlockAsset()` removes it directly.
 * - `setProperty(int $assetId, ...)` is self-contained: it acquires the distributed asset lock itself.
 *   In this tree only `lockAsset()` calls it.
 * - `setPropertyOnLockedAsset()` / `bulkSetPropertyOnLockedAssets()` assume the CALLER already holds the
 *   distributed mutation lock for the asset(s). Here "locked" means "distributed mutation lock held", not
 *   "asset protection property enabled" - do not confuse the two. Their callers are the reviewed metadata
 *   service ({@see AssetMetadataMutationServiceInterface}, which owns the signed plan, reviewed
 *   fingerprints, lock coordination, and observer warnings) and the durable `SetPropertyAction` rule
 *   action; the supported interactive metadata workflow goes through the metadata service.
 */
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
