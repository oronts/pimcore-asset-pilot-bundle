<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface UnusedAssetFinderInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: ?int, page: int, pages: ?int, hasMore: bool, truncated: bool}
     */
    public function findUnused(array $filters = [], int $page = 1, int $limit = 50, ?string $sort = null, ?string $order = null): array;

    /**
     * Stream every natively-visible unused asset for a CSV export. The generator return value is `true` when
     * the row ceiling cut the export short, so a caller can read `->getReturn()` and mark it truncated.
     *
     * @param array<string, mixed> $filters
     *
     * @return \Generator<int, array<string, mixed>, mixed, bool>
     */
    public function iterateForExport(array $filters = [], ?string $sort = null, ?string $order = null): \Generator;

    /** @param array<string, mixed> $filters */
    public function countUnused(array $filters = []): int;

    /** Whether any tracked dependency still targets this asset (re-verify before a destructive op). */
    public function isReferenced(int $assetId): bool;

    /**
     * Read-only preview of the delete/move guard for one asset id: the skip reason, or null when the
     * asset would actually be acted on. Lets a dry run report the real per-asset outcome.
     */
    public function previewMutation(int $id, string $action = 'delete'): ?string;

    /** @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, unknownSizeCount: int, byType: array<int, array{type: string, count: int, total_size: int, unknown_size_count: int}>} */
    public function getUnusedStats(): array;

    /**
     * Cached variant of getUnusedStats() for the web endpoint (the raw scan is too costly per request).
     *
     * @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, unknownSizeCount: int, byType: array<int, array{type: string, count: int, total_size: int, unknown_size_count: int}>}
     */
    public function getUnusedStatsCached(): array;

    /**
     * @param int[] $assetIds
     * @return array{deleted: int, failed: int, errors: array<int, string>, observerWarnings: list<string>}
     */
    public function deleteAssets(array $assetIds, ?array $expectedFingerprints = null): array;

    /**
     * @param int[] $assetIds
     * @return array{moved: int, failed: int, errors: array<int, string>, observerWarnings: list<string>}
     */
    public function moveAssets(array $assetIds, string $targetFolder, ?array $expectedFingerprints = null): array;
}
