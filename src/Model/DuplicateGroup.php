<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * A set of assets whose stored binaries are byte-identical (same content hash).
 */
readonly class DuplicateGroup
{
    /**
     * @param list<int> $assetIds
     */
    public function __construct(
        public string $checksum,
        public int $fileSize,
        public int $count,
        public array $assetIds,
    ) {}
}
