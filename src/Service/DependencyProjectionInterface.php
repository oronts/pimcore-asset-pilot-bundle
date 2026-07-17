<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DependencyReferenceSnapshot;
use Oronts\AssetPilotBundle\Model\DependencySourceToken;
use Pimcore\Model\Element\AbstractElement;

interface DependencyProjectionInterface
{
    public function markDirty(string $sourceType, int $sourceId): DependencySourceToken;

    public function markPending(string $sourceType): DependencySourceToken;

    public function refresh(AbstractElement $source, DependencySourceToken $token): bool;

    public function remove(string $sourceType, int $sourceId, ?DependencySourceToken $token = null): void;

    public function discard(DependencySourceToken $token): void;

    public function hasAssetReference(int $assetId): bool;

    /**
     * Read "is this asset referenced" and "is any source dirty" in one atomic statement, so a deletion
     * safety check cannot straddle a concurrent refresh commit.
     */
    public function referenceSnapshot(int $assetId): DependencyReferenceSnapshot;
}
