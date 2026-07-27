<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Support\UniqueServiceMap;
use Oronts\AssetPilotBundle\Zip\FlatZipStrategy;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipBuildResult;
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
class AssetZipService implements AssetZipServiceInterface
{
    /** @var array<string, ZipEntryStrategyInterface> */
    private array $strategies = [];

    /**
     * @param iterable<ZipEntryStrategyInterface> $strategies
     */
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly AssetFieldExtractorInterface $fieldExtractor,
        protected readonly ElementAuthorizationInterface $authorization,
        iterable $strategies = [],
        protected readonly string $defaultStrategy = 'flat',
        protected readonly int $maxAssets = 1000,
        protected readonly int $maxUncompressedBytes = 536870912,
    ) {
        $this->strategies = UniqueServiceMap::from($strategies, static fn (ZipEntryStrategyInterface $strategy): string => $strategy->getName(), 'ZIP strategy');
    }

    /** @param list<int> $assetIds */
    public function buildFromAssetIds(array $assetIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        return $this->build($this->downloadableAssets($assetIds, $actor), $options, count($assetIds));
    }

    public function buildFromFolder(int $folderId, bool $recursive = true, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        $assets = $this->assetsInFolder($folderId, $recursive, $actor);

        return $this->build($assets, $options, count($assets));
    }

    /**
     * Zip every asset referenced by the given data objects (across all field types the extractor
     * supports: relations, bricks, field collections, localized and block fields).
     *
     * @param list<int> $objectIds
     */
    public function buildFromObjects(array $objectIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        $assets = [];
        foreach ($objectIds as $objectId) {
            $object = $this->loadObject((int) $objectId);
            // Authorize the source object, not just its assets: its asset associations disclose the object.
            if ($object === null || !$this->authorization->isAllowed($object, 'view', $actor)) {
                continue;
            }
            foreach ($this->fieldExtractor->extract($object) as $info) {
                foreach ($info->assets as $asset) {
                    if ($this->authorization->isAllowed($asset, 'view', $actor)) {
                        $assets[(int) $asset->getId()] = $asset;
                        $this->assertWithinLimit(count($assets));
                    }
                }
            }
        }

        return $this->build(array_values($assets), $options, count($assets));
    }

    /** @param list<Asset> $assets */
    protected function build(array $assets, ?ZipBuildOptions $options, ?int $requested = null): ZipBuildResult
    {
        $options ??= new ZipBuildOptions();
        $strategy = $this->resolveStrategy($options->strategy);

        $this->assertWithinLimit(count($assets));
        $requested ??= count($assets);
        $skipped = max(0, $requested - count($assets));
        if ($assets === []) {
            return $this->emptyBuildResult($requested, $skipped);
        }

        [$path, $zip] = $this->openArchive();
        try {
            [$added, $skipped] = $this->addAssetsToArchive($zip, $assets, $options, $strategy, $skipped);
            if (!$zip->close()) {
                throw new \RuntimeException('Could not finalize the zip archive.');
            }
        } catch (\Throwable $e) {
            $this->discardArchive($zip, $path);

            throw $e;
        }

        return $this->buildResult($path, $requested, $added, $skipped, $strategy);
    }

    /** @return array{string, \ZipArchive} */
    private function openArchive(): array
    {
        $path = $this->createScratchPath();
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new \RuntimeException('Could not create the zip archive.');
        }

        return [$path, $zip];
    }

    /**
     * @param list<Asset> $assets
     * @return array{int, int}
     */
    private function addAssetsToArchive(
        \ZipArchive $zip,
        array $assets,
        ZipBuildOptions $options,
        ZipEntryStrategyInterface $strategy,
        int $skipped,
    ): array {
        $added = 0;
        $uncompressedBytes = 0;
        $used = [];
        foreach ($assets as $asset) {
            if ($this->addAssetToArchive($zip, $asset, $options, $strategy, $uncompressedBytes, $used)) {
                ++$added;
            } else {
                ++$skipped;
            }
        }

        return [$added, $skipped];
    }

    /** @param array<string, int> $used */
    private function addAssetToArchive(
        \ZipArchive $zip,
        Asset $asset,
        ZipBuildOptions $options,
        ZipEntryStrategyInterface $strategy,
        int &$uncompressedBytes,
        array &$used,
    ): bool {
        $file = $this->localFileFor($asset, $options->thumbnail, $entryExtension);
        $entry = $file !== null && $this->isPackable($file)
            ? $this->safeEntryName($this->retargetExtension($strategy->entryPath($asset), $entryExtension))
            : null;
        if ($file === null || $entry === null) {
            return false;
        }

        $fileSize = filesize($file);
        if ($fileSize === false) {
            return false;
        }
        $uncompressedBytes += $fileSize;
        if ($uncompressedBytes > $this->maxUncompressedBytes) {
            throw new \LengthException(sprintf(
                'ZIP source data exceeds the configured %d-byte uncompressed limit.',
                $this->maxUncompressedBytes,
            ));
        }

        return $zip->addFile($file, $this->uniqueName($entry, $used));
    }

    private function discardArchive(\ZipArchive $zip, string $path): void
    {
        try {
            $zip->close();
        } catch (\Throwable) {
        }
        @unlink($path);
    }

    private function emptyBuildResult(int $requested, int $skipped): ZipBuildResult
    {
        return new ZipBuildResult(null, $requested, 0, $skipped);
    }

    private function buildResult(
        string $path,
        int $requested,
        int $added,
        int $skipped,
        ZipEntryStrategyInterface $strategy,
    ): ZipBuildResult {
        if ($added === 0) {
            @unlink($path);

            return $this->emptyBuildResult($requested, $skipped);
        }

        $this->logger->info('Asset Pilot: built archive ({added} assets, {skipped} skipped, strategy {strategy})', [
            'added' => $added,
            'skipped' => $skipped,
            'strategy' => $strategy->getName(),
        ]);

        return new ZipBuildResult($path, $requested, $added, $skipped);
    }

    /**
     * @param int[] $assetIds
     *
     * @return Asset[]
     */
    protected function downloadableAssets(array $assetIds, ?ActorContext $actor = null): array
    {
        $this->assertWithinLimit(count($assetIds));
        $assets = [];
        foreach ($assetIds as $id) {
            $asset = $this->loadAsset((int) $id);
            if ($asset === null || $asset instanceof Asset\Folder || !$this->authorization->isAllowed($asset, 'view', $actor)) {
                continue;
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @return Asset[]
     */
    protected function assetsInFolder(int $folderId, bool $recursive, ?ActorContext $actor = null): array
    {
        $folder = $this->loadAsset($folderId);
        if (!$folder instanceof Asset\Folder || !$this->authorization->isAllowed($folder, 'view', $actor)) {
            return [];
        }

        $base = rtrim((string) $folder->getRealFullPath(), '/') . '/';
        $listing = $this->folderListing();
        $listing->setCondition(
            'type != :folder AND path ' . ($recursive ? 'LIKE :path' . Like::CLAUSE : '= :exact'),
            $recursive
                ? ['folder' => 'folder', 'path' => Like::escape($base) . '%']
                : ['folder' => 'folder', 'exact' => $base],
        );
        $assets = [];
        $offset = 0;
        $pageSize = $this->maxAssets + 1;
        while (true) {
            $listing->setOffset($offset);
            $listing->setLimit($pageSize);
            $page = $listing->load();
            foreach ($page as $asset) {
                // Page then authorize, so unauthorized rows do not drop authorized assets later in a folder.
                if ($this->authorization->isAllowed($asset, 'view', $actor)) {
                    $assets[] = $asset;
                    $this->assertWithinLimit(count($assets));
                }
            }
            if (count($page) < $pageSize) {
                break;
            }
            $offset += $pageSize;
        }

        return $assets;
    }

    private function resolveStrategy(?string $name): ZipEntryStrategyInterface
    {
        $name ??= $this->defaultStrategy;
        if (isset($this->strategies[$name])) {
            return $this->strategies[$name];
        }
        if ($name === 'flat') {
            return new FlatZipStrategy();
        }

        throw new \InvalidArgumentException(sprintf('Unknown ZIP strategy "%s".', $name));
    }

    private function assertWithinLimit(int $count): void
    {
        if ($count > $this->maxAssets) {
            throw new \LengthException(sprintf(
                'ZIP request contains %d assets; the configured limit is %d. Narrow the selection and retry.',
                $count,
                $this->maxAssets,
            ));
        }
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
