<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\HealResult;

/**
 * The fingerprint-guarded heal preview shared by the CLI and API heal entrypoints: it previews each asset and
 * re-checks the fingerprint map before and after, so a caller can reject a reviewed selection whose assets
 * changed mid-preview. The using class must expose `$this->healFingerprints` and `$this->healer`.
 */
trait PreviewsHealPlan
{
    /**
     * @param list<int> $assetIds
     *
     * @return array{array<int, HealResult>, ?array<string, string>} [results, after] — after is null when an asset
     *   changed during the preview, so the reviewed selection is stale
     */
    private function previewHealPlan(array $assetIds): array
    {
        $before = $this->healFingerprints->fingerprintMap($assetIds);
        $results = [];
        foreach ($assetIds as $assetId) {
            $results[$assetId] = $this->healer->previewById($assetId);
        }
        $after = $this->healFingerprints->fingerprintMap($assetIds);

        return [$results, $before === $after ? $after : null];
    }
}
