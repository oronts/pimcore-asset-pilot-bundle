<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Zip\FlatZipStrategy;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipEntryStrategyInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

/**
 * Builds a zip archive of assets for joint download (the content-manager "cart"), from one of three
 * sources: explicit asset ids, an asset folder (optionally recursive), or the assets referenced by
 * data objects. The in-archive layout is chosen by a pluggable ZipEntryStrategyInterface (flat /
 * folder-tree / by-type / custom) and image assets can be packed as a named Pimcore thumbnail.
 *
 * Storage model: assets are read through Pimcore's `getLocalFile()`, so any asset storage adapter
 * works (local, S3, …) — the binaries are pulled from wherever they live. The archive is built into a
 * local scratch file (ZipArchive needs a real path) and the caller streams it in the SAME request,
 * then deletes it. That keeps it robust across multiple pods: one pod builds AND serves a synchronous
 * download, so there is no cross-pod artifact to lose (unlike writing the zip to a separate download
 * storage, which only works when a shared adapter is configured). The build is bounded by
 * `zip.max_assets` to stay within a request; for very large sets, run it from a worker/CLI.
 *
 * Reusable standalone: call buildFromAssetIds()/buildFromFolder()/buildFromObjects() and stream the
 * returned path; register a ZipEntryStrategyInterface to add an archive layout.
 */
class AssetZipService
{
    /** @var array<string, ZipEntryStrategyInterface> */
    private array $strategies = [];

    /**
     * @param iterable<ZipEntryStrategyInterface> $strategies
     */
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly AssetFieldExtractorInterface $fieldExtractor,
        iterable $strategies = [],
        protected readonly string $defaultStrategy = 'flat',
        protected readonly int $maxAssets = 1000,
    ) {
        foreach ($strategies as $strategy) {
            $this->strategies[$strategy->getName()] = $strategy;
        }
    }

    /**
     * @param int[] $assetIds
     *
     * @return array{path: ?string, added: int, skipped: int}
     */
    public function buildFromAssetIds(array $assetIds, ?ZipBuildOptions $options = null): array
    {
        return $this->build($this->downloadableAssets($assetIds), $options);
    }

    /**
     * @return array{path: ?string, added: int, skipped: int}
     */
    public function buildFromFolder(int $folderId, bool $recursive = true, ?ZipBuildOptions $options = null): array
    {
        return $this->build($this->assetsInFolder($folderId, $recursive), $options);
    }

    /**
     * Zip every asset referenced by the given data objects (across all field types the extractor
     * supports: relations, bricks, field collections, localized and block fields).
     *
     * @param int[] $objectIds
     *
     * @return array{path: ?string, added: int, skipped: int}
     */
    public function buildFromObjects(array $objectIds, ?ZipBuildOptions $options = null): array
    {
        $assets = [];
        foreach ($objectIds as $objectId) {
            if (count($assets) >= $this->maxAssets) {
                break;
            }
            $object = $this->loadObject((int) $objectId);
            if ($object === null) {
                continue;
            }
            foreach ($this->fieldExtractor->extract($object) as $info) {
                foreach ($info->assets as $asset) {
                    if ($asset->isAllowed('view')) {
                        $assets[(int) $asset->getId()] = $asset;
                    }
                }
            }
        }

        return $this->build(array_values($assets), $options);
    }

    /**
     * @param Asset[] $assets
     *
     * @return array{path: ?string, added: int, skipped: int}
     */
    protected function build(array $assets, ?ZipBuildOptions $options): array
    {
        $options ??= new ZipBuildOptions();
        $strategy = $this->resolveStrategy($options->strategy);

        $capped = array_slice($assets, 0, $this->maxAssets);
        $skipped = count($assets) - count($capped);

        if ($capped === []) {
            return ['path' => null, 'added' => 0, 'skipped' => $skipped];
        }

        $path = $this->createScratchPath();
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new \RuntimeException('Could not create the zip archive.');
        }

        $added = 0;
        $used = [];
        try {
            foreach ($capped as $asset) {
                $file = $this->localFileFor($asset, $options->thumbnail, $entryExtension);
                // safeEntryName rejects zip-slip paths (absolute, .., backslash, control chars) that a
                // custom strategy could emit; a missing, empty, or unreadable asset is skipped, not
                // packed (a failed storage read surfaces in Pimcore as a 0-byte temp file).
                $entry = $file !== null && $this->isPackable($file)
                    ? $this->safeEntryName($this->retargetExtension($strategy->entryPath($asset), $entryExtension))
                    : null;
                if ($file === null || $entry === null) {
                    $skipped++;
                    continue;
                }
                // Count what actually landed in the archive: if libzip refuses the entry (e.g. the
                // file vanished between the check and the add), report it skipped, not added.
                if ($zip->addFile($file, $this->uniqueName($entry, $used))) {
                    $added++;
                } else {
                    $skipped++;
                }
            }
            $zip->close();
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($path);

            throw $e;
        }

        if ($added === 0) {
            @unlink($path);

            return ['path' => null, 'added' => 0, 'skipped' => $skipped];
        }

        $this->logger->info('Asset Pilot: built archive ({added} assets, {skipped} skipped, strategy {strategy})', [
            'added' => $added,
            'skipped' => $skipped,
            'strategy' => $strategy->getName(),
        ]);

        return ['path' => $path, 'added' => $added, 'skipped' => $skipped];
    }

    /**
     * @param int[] $assetIds
     *
     * @return Asset[]
     */
    protected function downloadableAssets(array $assetIds): array
    {
        $assets = [];
        foreach ($assetIds as $id) {
            // Stop loading once the cap is reached so a huge id list cannot exhaust memory before build().
            if (count($assets) >= $this->maxAssets) {
                break;
            }
            $asset = $this->loadAsset((int) $id);
            // Per-asset Pimcore workspace ACL on top of the View permission; isAllowed() returns true on CLI.
            if ($asset === null || $asset instanceof Asset\Folder || !$asset->isAllowed('view')) {
                continue;
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @return Asset[]
     */
    protected function assetsInFolder(int $folderId, bool $recursive): array
    {
        $folder = $this->loadAsset($folderId);
        if (!$folder instanceof Asset\Folder || !$folder->isAllowed('view')) {
            return [];
        }

        $base = rtrim((string) $folder->getRealFullPath(), '/') . '/';
        $listing = $this->folderListing();
        $listing->setCondition(
            'type != :folder AND path ' . ($recursive ? 'LIKE :path' : '= :exact'),
            $recursive
                ? ['folder' => 'folder', 'path' => str_replace(['%', '_'], ['\\%', '\\_'], $base) . '%']
                : ['folder' => 'folder', 'exact' => $base],
        );
        $listing->setLimit($this->maxAssets + 1);

        $assets = [];
        foreach ($listing->load() as $asset) {
            if ($asset->isAllowed('view')) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    private function resolveStrategy(?string $name): ZipEntryStrategyInterface
    {
        return $this->strategies[$name ?? $this->defaultStrategy]
            ?? $this->strategies[$this->defaultStrategy]
            ?? new FlatZipStrategy();
    }

    /**
     * @param-out ?string $entryExtension the extension the packed file actually has (thumbnail format)
     */
    protected function localFileFor(Asset $asset, ?string $thumbnail, ?string &$entryExtension = null): ?string
    {
        $entryExtension = null;

        if ($thumbnail !== null && $thumbnail !== '' && $asset instanceof Asset\Image) {
            try {
                $local = $asset->getThumbnail($thumbnail)->getLocalFile();
                if ($local !== null && is_file($local)) {
                    $entryExtension = pathinfo($local, PATHINFO_EXTENSION) ?: null;

                    return $local;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Asset Pilot: thumbnail "{t}" failed for asset {id}, using original: {error}', [
                    't' => $thumbnail,
                    'id' => $asset->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            return $asset->getLocalFile();
        } catch (\Throwable $e) {
            // getLocalFile() throws if the binary cannot be materialised locally (e.g. a remote
            // storage read fails). Skip this asset instead of aborting the whole archive build.
            $this->logger->warning('Asset Pilot: could not resolve a local file for asset {id}, skipping it: {error}', [
                'id' => $asset->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A resolved local file is packable only if it exists and is non-empty. A failed Storage read
     * surfaces in Pimcore as a 0-byte temp file that would otherwise be packed as an empty entry.
     */
    private function isPackable(string $file): bool
    {
        return is_file($file) && @filesize($file) > 0;
    }

    private function retargetExtension(string $entry, ?string $newExtension): string
    {
        if ($newExtension === null || $newExtension === '') {
            return $entry;
        }
        $current = pathinfo($entry, PATHINFO_EXTENSION);
        if ($current === '') {
            return $entry . '.' . $newExtension;
        }
        if (strcasecmp($current, $newExtension) === 0) {
            return $entry;
        }

        return substr($entry, 0, -(strlen($current) + 1)) . '.' . $newExtension;
    }

    /**
     * Normalise a strategy-provided archive entry path and reject zip-slip: drop backslashes, leading
     * slashes, `.`/`..` segments and control characters. Returns null when nothing valid remains.
     */
    protected function safeEntryName(string $entry): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $entry)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            if (preg_match('/[\x00-\x1f]/', $segment) === 1) {
                return null;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /** @param array<string, int> $used */
    protected function uniqueName(string $entry, array &$used): string
    {
        $entry = $entry !== '' ? $entry : 'asset';
        if (!isset($used[$entry])) {
            $used[$entry] = 1;

            return $entry;
        }

        $n = ++$used[$entry];
        $dir = \dirname($entry);
        $dir = $dir === '.' ? '' : $dir . '/';
        $base = basename($entry);
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        if ($ext === '') {
            return $dir . $base . '-' . $n;
        }

        return $dir . substr($base, 0, -(strlen($ext) + 1)) . '-' . $n . '.' . $ext;
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return DataObject::getById($id);
    }

    protected function folderListing(): Asset\Listing
    {
        return new Asset\Listing();
    }

    protected function createScratchPath(): string
    {
        $dir = \defined('PIMCORE_SYSTEM_TEMP_DIRECTORY') && is_dir(PIMCORE_SYSTEM_TEMP_DIRECTORY)
            ? PIMCORE_SYSTEM_TEMP_DIRECTORY
            : sys_get_temp_dir();

        return (string) tempnam($dir, 'asset_pilot_zip_');
    }
}
