<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

interface AssetIntegrityServiceInterface
{
    public function check(Asset $asset): IntegrityResult;

    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return array{items: list<array{id: int, path: string, checker: string, reason: ?string}>, scanned: int, broken: int, page: int, limit: int, hasNext: bool}
     */
    public function findBroken(array $filters = [], int $page = 1, int $limit = 50): array;

    /**
     * @param int[] $ids
     * @return array{items: list<array{id: int, path: string, checker: string, reason: ?string}>, scanned: int, broken: int, page: int, limit: int, hasNext: bool}
     */
    public function checkAssets(array $ids): array;
}
