<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Oronts\AssetPilotBundle\Service\Query\AssetFilter;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\Service as ElementService;
use Psr\Log\LoggerInterface;

/**
 * Renames assets whose filename is not a valid/normalized Pimcore asset key to the sanitized form
 * (via the native Element\Service::getValidKey). Destructive (a rename changes the path), so it is
 * dry-run-first, per-asset ACL-checked, content-reference guarded (a hard-coded path reference would
 * break, like a move), and protected by the same renewable locks as the move pipeline. Preview and
 * apply evaluate the same safety gates. The scan is paged and bounded.
 */
class NormalizeFilenamesService
{
    public function __construct(
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly ContentUsageScanner $contentScanner,
    ) {}

    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return list<int>
     */
    public function findCandidates(array $filters = [], int $limit = 100): array
    {
        $ids = [];
        foreach ($this->listAssetIds($filters, 0, max(1, $limit)) as $id) {
            $asset = $this->loadAsset((int) $id);
            if ($asset === null || $asset instanceof Asset\Folder) {
                continue;
            }
            $filename = (string) $asset->getFilename();
            $valid = $this->validKey($filename);
            if ($valid !== '' && $valid !== $filename) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @param int[] $assetIds
     * @return array{renamed: int, skipped: int, failed: int, errors: array<int, string>, changes: list<array{id: int, from: string, to: string}>}
     */
    public function normalize(array $assetIds, bool $dryRun = true): array
    {
        $renamed = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $changes = [];

        foreach ($assetIds as $id) {
            $id = (int) $id;
            try {
                $outcome = $this->normalizeAsset($id, $dryRun);
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                ++$failed;

                continue;
            }

            if ($outcome['state'] === 'skipped') {
                ++$skipped;
                continue;
            }
            if ($outcome['state'] === 'failed') {
                $errors[$id] = $outcome['error'];
                ++$failed;
                continue;
            }

            $changes[] = $outcome['change'];
            if ($outcome['state'] === 'renamed') {
                ++$renamed;
            }
        }

        return ['renamed' => $renamed, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors, 'changes' => $changes];
    }

    /**
     * @return array{
     *     state: 'skipped'|'failed'|'planned'|'renamed',
     *     error?: string,
     *     change?: array{id: int, from: string, to: string}
     * }
     */
    private function normalizeAsset(int $assetId, bool $dryRun): array
    {
        if (!$this->loopGuard->acquireAsset($assetId)) {
            return ['state' => 'failed', 'error' => 'Asset is being processed by another job'];
        }

        $targetPath = null;
        try {
            $asset = $this->reloadAsset($assetId);
            if ($asset === null || $asset instanceof Asset\Folder) {
                return ['state' => 'skipped'];
            }

            $current = (string) $asset->getFilename();
            $valid = $this->validKey($current);
            if ($valid === '' || $valid === $current) {
                return ['state' => 'skipped'];
            }
            if (!$asset->isAllowed('publish')) {
                return ['state' => 'failed', 'error' => 'Not permitted to rename this asset'];
            }
            if (!$this->contentScanner->canVerify()) {
                return ['state' => 'failed', 'error' => 'Content-reference verification is not configured'];
            }
            if ($this->contentScanner->isReferencedInContent($asset)) {
                return ['state' => 'failed', 'error' => 'Asset is referenced in object content (text/WYSIWYG)'];
            }

            $targetPath = $this->targetPath($asset, $valid);
            if (!$this->loopGuard->acquireTarget($targetPath)) {
                return ['state' => 'failed', 'error' => 'Target path is being allocated by another job'];
            }

            $occupant = $this->assetAtPath($targetPath);
            if ($occupant !== null && (int) $occupant->getId() !== $assetId) {
                return ['state' => 'failed', 'error' => sprintf('An asset named "%s" already exists in this folder.', $valid)];
            }

            $change = ['id' => $assetId, 'from' => $current, 'to' => $valid];
            if ($dryRun) {
                return ['state' => 'planned', 'change' => $change];
            }

            $this->renameGuarded($asset, $assetId, $valid, $targetPath);
            $this->logger->info('Asset Pilot: normalized asset {id} filename {from} -> {to}', [
                'id' => $assetId,
                'from' => $current,
                'to' => $valid,
            ]);

            return ['state' => 'renamed', 'change' => $change];
        } finally {
            if ($targetPath !== null) {
                $this->loopGuard->releaseTarget($targetPath);
            }
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    protected function renameGuarded(Asset $asset, int $assetId, string $filename, string $targetPath): void
    {
        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $asset->setFilename($filename);
            $this->loopGuard->refreshAsset($assetId);
            $this->loopGuard->refreshTarget($targetPath);
            $asset->save(['versionNote' => 'Asset Pilot: normalized filename to ' . $filename]);
            $this->loopGuard->markAssetRecentlyMoved($assetId);
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException(sprintf('An asset named "%s" already exists in this folder.', $filename), 0, $e);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
        }
    }

    protected function validKey(string $filename): string
    {
        return ElementService::getValidKey($filename, 'asset');
    }

    protected function reloadAsset(int $id): ?Asset
    {
        return Asset::getById($id, ['force' => true]);
    }

    protected function assetAtPath(string $path): ?Asset
    {
        return Asset::getByPath($path);
    }

    private function targetPath(Asset $asset, string $filename): string
    {
        return rtrim(dirname($asset->getRealFullPath()), '/') . '/' . $filename;
    }

    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return list<int>
     */
    protected function listAssetIds(array $filters, int $offset, int $limit): array
    {
        [$condition, $params] = AssetFilter::condition($filters, excludeFolders: true);

        $listing = new Asset\Listing();
        $listing->setCondition($condition, $params);
        $listing->setOffset(max(0, $offset));
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }
}
