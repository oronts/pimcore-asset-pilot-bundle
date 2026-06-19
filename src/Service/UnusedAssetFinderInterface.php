<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface UnusedAssetFinderInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function findUnused(array $filters = [], int $page = 1, int $limit = 50, ?string $sort = null, ?string $order = null): array;

    /** @param array<string, mixed> $filters */
    public function countUnused(array $filters = []): int;

    /** Whether any tracked dependency still targets this asset (re-verify before a destructive op). */
    public function isReferenced(int $assetId): bool;

    /** @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, byType: array<int, array{type: string, count: int, total_size: int}>} */
    public function getUnusedStats(): array;

    /**
     * Cached variant of getUnusedStats() for the web endpoint (the raw scan is too costly per request).
     *
     * @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, byType: array<int, array{type: string, count: int, total_size: int}>}
     */
    public function getUnusedStatsCached(): array;

    /**
     * @param int[] $assetIds
     * @return array{deleted: int, failed: int, errors: array<int, string>}
     */
    public function deleteAssets(array $assetIds): array;

    /**
     * @param int[] $assetIds
     * @return array{moved: int, failed: int, errors: array<int, string>}
     */
    public function moveAssets(array $assetIds, string $targetFolder): array;
}
