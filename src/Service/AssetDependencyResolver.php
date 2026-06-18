<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Resolves the DataObjects that reference an asset, via Pimcore's reverse-dependency lookup. The
 * reverse-dependency query is paged (Pimcore applies a LIMIT only when both offset and limit are
 * given) and the result is bounded, so a heavily-referenced asset never triggers an unbounded load.
 */
class AssetDependencyResolver
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<int> unique DataObject ids that reference the asset, capped at $limit
     */
    public function dependentObjectIds(int $assetId, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $seen = [];
        $ids = [];
        $offset = 0;

        while (count($ids) < $limit) {
            try {
                $rows = $this->loadRequiredBy($assetId, $offset, self::PAGE_SIZE);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: failed to read reverse dependencies for asset {id}: {error}', [
                    'id' => $assetId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                break;
            }

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (($row['type'] ?? null) !== 'object') {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $ids[] = $id;
                    if (count($ids) >= $limit) {
                        break 2;
                    }
                }
            }

            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        return $ids;
    }

    /**
     * @return list<array{id: int|string, type: string}>
     */
    protected function loadRequiredBy(int $assetId, int $offset, int $limit): array
    {
        $asset = Asset::getById($assetId);
        if ($asset === null) {
            return [];
        }

        return $asset->getDependencies()->getRequiredBy($offset, $limit);
    }
}
