<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ApplyPlan;

interface EmptyFolderSweepServiceInterface
{
    /** @return array{items: list<array{id: int, path: string}>, page: int, limit: int, hasMore: bool} */
    public function findEmpty(?string $root = null, int $page = 1, int $limit = 100): array;

    /** @param list<int> $folderIds */
    public function createDeletePlan(array $folderIds): ApplyPlan;

    /** @param list<int> $folderIds @return array{deleted: 0, eligible: int, skipped: int, failed: int, errors: array<int, string>} */
    public function previewDelete(array $folderIds): array;

    /**
     * @param list<int> $folderIds
     * @param array<string, string> $expectedFingerprints
     * @return array{deleted: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function deleteEmpty(array $folderIds, array $expectedFingerprints): array;
}
