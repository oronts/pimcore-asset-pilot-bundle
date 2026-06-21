<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

/**
 * The result of merging one duplicate group: the canonical asset that was kept and the disposition of
 * every other copy. {@see $canonicalId} is 0 when nothing was merged (no such group or a single asset).
 */
readonly class MergeOutcome
{
    /**
     * @param list<CopyDisposition> $dispositions
     */
    public function __construct(
        public string $checksum,
        public int $canonicalId,
        public array $dispositions,
    ) {}
}
