<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface StorageTrendServiceInterface
{
    /** @return array{captured: bool, runId: int|null, capturedAt: string|null, types: int, totalCount: int, totalSize: int, unknownSizeCount: int, reason: string|null} */
    public function capture(bool $force = false): array;

    /** @return list<array{capturedAt: string, count: int, size: int, unknownSizeCount: int}> */
    public function trend(?string $type = null, int $limit = 90): array;

    /** @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, unknownSizeCount: int, byType: list<array{type: string, count: int, total_size: int, unknown_size_count: int}>}|null */
    public function latestUnusedStats(): ?array;
}
