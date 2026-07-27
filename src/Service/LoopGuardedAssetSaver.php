<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Pimcore\Model\Asset;

readonly class LoopGuardedAssetSaver
{
    public function __construct(private LoopGuard $loopGuard) {}

    /**
     * @param callable(Asset): void|null $mutate
     * @param array<string, mixed>       $saveParameters
     * @param callable(): void|null      $refreshLeases
     */
    public function save(
        Asset $asset,
        ?callable $mutate = null,
        array $saveParameters = [],
        ?callable $refreshLeases = null,
    ): void {
        $assetId = (int) $asset->getId();
        if ($assetId <= 0) {
            throw new \InvalidArgumentException('A persisted asset with a positive id is required.');
        }

        $this->loopGuard->markAssetProcessing($assetId);

        try {
            if ($mutate !== null) {
                $mutate($asset);
            }

            $this->loopGuard->refreshAsset($assetId);
            if ($refreshLeases !== null) {
                $refreshLeases();
            }

            $asset->save($saveParameters);
            $this->loopGuard->markAssetRecentlyMoved($assetId);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
        }
    }
}
