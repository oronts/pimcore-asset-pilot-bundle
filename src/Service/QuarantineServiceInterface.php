<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;

interface QuarantineServiceInterface
{
    /** @param int[] $assetIds @param array<string, string>|null $expectedFingerprints @return array{quarantined: int, failed: int, errors: array<int|string, string>} */
    public function quarantine(array $assetIds, ?array $expectedFingerprints = null): array;

    public function recoverQuarantine(int $assetId): bool;

    public function previewQuarantine(int $assetId): ?string;

    public function restore(int $assetId): bool;

    /** @param array{type?: string, before?: string, after?: string} $filters @return array{items: array<int, array<string, mixed>>, total: ?int, page: int, pages: ?int, hasMore: bool, truncated: bool} */
    public function listQuarantined(int $page = 1, int $limit = 50, array $filters = []): array;

    /**
     * Stream every natively-visible quarantined asset for a CSV export. The generator return value is `true`
     * when the row ceiling cut the export short, so a caller can read `->getReturn()` and mark it truncated.
     *
     * @param array<string, mixed> $filters
     *
     * @return \Generator<int, array<string, mixed>, mixed, bool>
     */
    public function iterateForExport(array $filters = []): \Generator;

    /** @return array{purged: int, skipped: int, failed: int} */
    public function purgeExpired(?int $graceDays = null, bool $dryRun = false): array;

    /**
     * @return array{
     *     graceDays: int,
     *     assetIds: list<int>,
     *     config: array<string, mixed>,
     *     targets: list<ApplyPlanTarget>,
     *     result: array{purged: int, skipped: int, failed: int}
     * }
     */
    public function previewPurge(?int $graceDays = null): array;

    /** @param list<int> $assetIds @param array<string, string> $expectedFingerprints @return array{purged: int, skipped: int, failed: int} */
    public function purgePlanned(?int $graceDays, array $assetIds, array $expectedFingerprints): array;
}
