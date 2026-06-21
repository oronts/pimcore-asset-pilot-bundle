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

    /** Upper-bound (days) of the "recently uploaded" bucket, reused by the SQL confidence filter. */
    public function getRecentlyUploadedDays(): int;

    /** Upper-bound (days) of the "probably unused" bucket, reused by the SQL confidence filter. */
    public function getProbablyUnusedDays(): int;
}
