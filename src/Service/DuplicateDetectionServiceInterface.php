<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DuplicateGroup;

interface DuplicateDetectionServiceInterface
{
    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return array{scanned: int, indexed: int, skipped: int}
     */
    public function index(array $filters = [], int $limit = 1000): array;

    /** @return list<DuplicateGroup> */
    public function findDuplicates(int $page = 1, int $limit = 50, int $minCopies = 2, ?string $type = null, array $filters = []): array;

    /** @return array{groups: list<\Oronts\AssetPilotBundle\Model\DuplicateGroup>, hasMore: bool, truncated: bool} */
    public function findDuplicatePage(int $page = 1, int $limit = 50, int $minCopies = 2, ?string $type = null, array $filters = []): array;

    /** @return \Generator<int, \Oronts\AssetPilotBundle\Model\DuplicateGroup, mixed, bool> */
    public function iterateForExport(int $minCopies = 2, ?string $type = null, array $filters = []): \Generator;

    public function countDuplicateGroups(int $minCopies = 2, ?string $type = null, array $filters = []): int;

    public function groupForChecksum(string $checksum): ?DuplicateGroup;

    public function groupForAsset(int $assetId): ?DuplicateGroup;
}
