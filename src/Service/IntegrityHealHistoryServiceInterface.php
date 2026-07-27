<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface IntegrityHealHistoryServiceInterface
{
    /**
     * @return array{
     *     items: list<array{id: int, assetId: int, path: string, fromVersion: int, toVersion: ?int, checker: string, status: string, createdAt: string, eligible: bool, eligibilityReason: ?string, reason: ?string}>,
     *     total: ?int,
     *     page: int,
     *     pages: ?int,
     *     hasMore: bool,
     *     truncated: bool
     * }
     */
    public function getPaginated(int $page = 1, int $limit = 25): array;
}
