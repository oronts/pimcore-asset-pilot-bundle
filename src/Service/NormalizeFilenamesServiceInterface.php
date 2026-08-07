<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface NormalizeFilenamesServiceInterface
{
    /** @param array{type?: string, folder?: string, extension?: string} $filters @return list<int> */
    public function findCandidates(array $filters = [], int $limit = 100): array;

    /**
     * @param int[] $assetIds
     * @param array<string, string>|null $expectedFingerprints
     * @return array{renamed: int, skipped: int, failed: int, errors: array<int, string>, changes: list<array{id: int, from: string, to: string}>}
     */
    public function normalize(array $assetIds, bool $dryRun = true, ?array $expectedFingerprints = null): array;
}
