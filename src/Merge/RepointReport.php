<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

/**
 * What the repointer did for one duplicate copy, and which reference surfaces it could not safely
 * rewrite. A copy is only ever disposed when nothing is blocked ({@see self::$fullyRepointed}).
 */
readonly class RepointReport
{
    public bool $fullyRepointed;

    /**
     * @param list<string> $blocked human-readable reasons a reference could not be repointed
     */
    public function __construct(
        public int $fromAssetId,
        public int $toAssetId,
        public int $repointedObjects,
        public array $blocked,
    ) {
        $this->fullyRepointed = $blocked === [];
    }
}
