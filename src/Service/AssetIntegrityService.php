<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Oronts\AssetPilotBundle\Service\Query\AssetFilter;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Detects assets whose binary no longer renders, via the tagged integrity checkers. The scan is
 * paged and bounded (it loads and render-tests one asset at a time), so a large catalog never blocks;
 * a whole-catalog sweep pages through or runs async. Detection is read-only — the version-rollback
 * heal is a separate, explicitly-guarded operation.
 */
class AssetIntegrityService
{
    public function __construct(
        protected readonly CompositeIntegrityChecker $checker,
        protected readonly LoggerInterface $logger,
        protected readonly bool $enabled = true,
        protected readonly array $skipExtensions = ['svg'],
    ) {}

    public function check(Asset $asset): IntegrityResult
    {
        return $this->checker->check($asset);
    }

    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return array{items: list<array{id: int, path: string, checker: string, reason: ?string}>, scanned: int, broken: int, page: int, limit: int}
     */
    public function findBroken(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);
        $empty = ['items' => [], 'scanned' => 0, 'broken' => 0, 'page' => $page, 'limit' => $limit];

        if (!$this->enabled) {
            return $empty;
        }

        $items = [];
        $scanned = 0;
        foreach ($this->listAssetIds($filters, ($page - 1) * $limit, $limit) as $id) {
            $asset = $this->loadAsset((int) $id);
            if ($asset === null || $asset instanceof Asset\Folder) {
                continue;
            }
            $extension = strtolower(pathinfo((string) $asset->getFilename(), PATHINFO_EXTENSION));
            if (in_array($extension, $this->skipExtensions, true)) {
                continue;
            }

            ++$scanned;
            $item = $this->brokenItem((int) $id, $asset);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'scanned' => $scanned, 'broken' => count($items), 'page' => $page, 'limit' => $limit];
    }

    /**
     * Inspect exactly the given asset ids (explicit, so neither the enabled flag nor skip_extensions
     * gate it — the caller named them). Folders and missing assets are skipped.
     *
     * @param int[] $ids
     * @return array{items: list<array{id: int, path: string, checker: string, reason: ?string}>, scanned: int, broken: int, page: int, limit: int}
     */
    public function checkAssets(array $ids): array
    {
        $items = [];
        $scanned = 0;
        foreach ($ids as $id) {
            $asset = $this->loadAsset((int) $id);
            if ($asset === null || $asset instanceof Asset\Folder) {
                continue;
            }
            ++$scanned;
            $item = $this->brokenItem((int) $id, $asset);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'scanned' => $scanned, 'broken' => count($items), 'page' => 1, 'limit' => count($ids)];
    }

    /**
     * @return array{id: int, path: string, checker: string, reason: ?string}|null the broken-item row, or null if not broken
     */
    private function brokenItem(int $id, Asset $asset): ?array
    {
        $result = $this->check($asset);
        if ($result->status !== IntegrityStatus::Broken) {
            return null;
        }

        return ['id' => $id, 'path' => $asset->getRealFullPath(), 'checker' => $result->checker, 'reason' => $result->reason];
    }

    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return list<int>
     */
    protected function listAssetIds(array $filters, int $offset, int $limit): array
    {
        [$condition, $params] = AssetFilter::condition($filters);

        $listing = new Asset\Listing();
        if ($condition !== '') {
            $listing->setCondition($condition, $params);
        }
        $listing->setOffset(max(0, $offset));
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }
}
