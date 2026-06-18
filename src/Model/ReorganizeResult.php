<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * Outcome of an asset-centric reorganize: assets scanned in the source folder, the distinct owner
 * objects resolved from them, and how those owners were re-organized (sync) or queued (async).
 */
readonly class ReorganizeResult
{
    public function __construct(
        public int $assetsScanned,
        public int $ownerObjects,
        public int $organized,
        public int $dispatched,
        public int $skipped,
        public int $failed,
    ) {}
}
