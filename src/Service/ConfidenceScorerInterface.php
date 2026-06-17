<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface ConfidenceScorerInterface
{
    /**
     * @param array[] $items UnusedAssetFinder result items
     * @return array[] Items with an added 'confidence' key
     */
    public function score(array $items): array;
}
