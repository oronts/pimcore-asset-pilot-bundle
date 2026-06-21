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
 * break, like a move), and the save is LoopGuard-wrapped so it does not re-enter the organize
 * pipeline. The scan is paged and bounded.
 */
class NormalizeFilenamesService
{
    public function __construct(
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly ?ContentUsageScanner $contentScanner = null,
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
                $asset = $this->loadAsset($id);
                if ($asset === null || $asset instanceof Asset\Folder) {
                    ++$skipped;
                    continue;
                }

                $current = (string) $asset->getFilename();
                $valid = $this->validKey($current);
                if ($valid === '' || $valid === $current) {
                    ++$skipped;
                    continue;
                }

                if (!$asset->isAllowed('publish')) {
                    $errors[$id] = 'Not permitted to rename this asset';
                    ++$failed;
                    continue;
                }

                // A rename changes the path, which would break a hard-coded path reference in content.
                if ($this->isReferencedInContent($asset)) {
                    $errors[$id] = 'Asset is referenced in object content (text/WYSIWYG)';
                    ++$failed;
                    continue;
                }

                $changes[] = ['id' => $id, 'from' => $current, 'to' => $valid];
                if ($dryRun) {
                    continue;
                }

                $this->renameGuarded($asset, $id, $valid);
                ++$renamed;
                $this->logger->info('Asset Pilot: normalized asset {id} filename {from} -> {to}', [
                    'id' => $id,
                    'from' => $current,
                    'to' => $valid,
                ]);
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                ++$failed;
            }
        }

        return ['renamed' => $renamed, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors, 'changes' => $changes];
    }

    protected function renameGuarded(Asset $asset, int $assetId, string $filename): void
    {
        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $asset->setFilename($filename);
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

    private function isReferencedInContent(Asset $asset): bool
    {
        return $this->contentScanner?->isReferencedInContent($asset) === true;
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
