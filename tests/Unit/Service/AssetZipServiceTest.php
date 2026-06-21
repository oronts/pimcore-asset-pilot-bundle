<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetZipService;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(AssetZipService::class)]
class AssetZipServiceTest extends TestCase
{
    #[Test]
    public function onlyIncludesReadableNonFolderAssets(): void
    {
        $allowed = $this->createMock(Asset::class);
        $allowed->method('isAllowed')->with('view')->willReturn(true);

        $denied = $this->createMock(Asset::class);
        $denied->method('isAllowed')->with('view')->willReturn(false);

        $folder = $this->createMock(Asset\Folder::class);

        $service = $this->serviceWith([1 => $allowed, 2 => $denied, 3 => $folder, 4 => null]);

        self::assertSame([$allowed], $service->downloadable([1, 2, 3, 4]));
    }

    #[Test]
    public function capsLoadedAssetsAtMaxAssets(): void
    {
        $assets = [];
        for ($id = 1; $id <= 5; $id++) {
            $a = $this->createMock(Asset::class);
            $a->method('isAllowed')->willReturn(true);
            $assets[$id] = $a;
        }

        self::assertCount(2, $this->serviceWith($assets, 2)->downloadable([1, 2, 3, 4, 5]));
    }

    #[Test]
    #[DataProvider('zipSlipEntries')]
    public function rejectsOrNeutralisesZipSlipEntryNames(string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->serviceWith([])->safe($input));
    }

    public static function zipSlipEntries(): array
    {
        return [
            'parent traversal' => ['../../etc/passwd', 'etc/passwd'],
            'absolute path' => ['/etc/passwd', 'etc/passwd'],
            'backslash + dotdot' => ['a\\..\\..\\b.png', 'a/b.png'],
            'interior dotdot' => ['a/../b.png', 'a/b.png'],
            'control char' => ["x\x00y.png", null],
            'all unsafe' => ['../..', null],
            'clean nested' => ['Products/cover.jpg', 'Products/cover.jpg'],
        ];
    }

    #[Test]
    public function deDuplicatesCollidingEntryNames(): void
    {
        $service = $this->serviceWith([]);
        $used = [];

        self::assertSame('a.png', $service->unique('a.png', $used));
        self::assertSame('a-2.png', $service->unique('a.png', $used));
        self::assertSame('a-3.png', $service->unique('a.png', $used));
        self::assertSame('dir/b', $service->unique('dir/b', $used));
        self::assertSame('dir/b-2', $service->unique('dir/b', $used));
    }

    /** @param array<int, ?Asset> $map */
    private function serviceWith(array $map, int $maxAssets = 1000): object
    {
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);

        return new class (new NullLogger(), $extractor, $map, $maxAssets) extends AssetZipService {
            /** @param array<int, ?Asset> $map */
            public function __construct(NullLogger $logger, AssetFieldExtractorInterface $extractor, private readonly array $map, int $maxAssets)
            {
                parent::__construct($logger, $extractor, [], 'flat', $maxAssets);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->map[$id] ?? null;
            }

            /**
             * @param int[] $ids
             * @return Asset[]
             */
            public function downloadable(array $ids): array
            {
                return $this->downloadableAssets($ids);
            }

            public function safe(string $entry): ?string
            {
                return $this->safeEntryName($entry);
            }

            /** @param array<string, int> $used */
            public function unique(string $entry, array &$used): string
            {
                return $this->uniqueName($entry, $used);
            }

            /**
             * @param Asset[] $assets
             * @return array{path: ?string, added: int, skipped: int}
             */
            public function callBuild(array $assets, ?ZipBuildOptions $options = null): array
            {
                return $this->build($assets, $options);
            }

            public function callLocalFileFor(Asset $asset): ?string
            {
                return $this->localFileFor($asset, null);
            }
        };
    }

    #[Test]
    public function aThrowingGetLocalFileIsSkippedNotFatal(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(42);
        $asset->method('getLocalFile')->willThrowException(new \RuntimeException('storage read failed'));

        self::assertNull($this->serviceWith([])->callLocalFileFor($asset));
    }

    #[Test]
    public function buildSkipsEmptyFilesAndPacksOnlyNonEmptyAssets(): void
    {
        $good = (string) tempnam(sys_get_temp_dir(), 'apz_good_');
        file_put_contents($good, 'real-bytes');
        // tempnam creates a 0-byte file: the same shape Pimcore's empty-tmpfile storage fallback yields.
        $empty = (string) tempnam(sys_get_temp_dir(), 'apz_empty_');

        $goodAsset = $this->createMock(Asset::class);
        $goodAsset->method('getFilename')->willReturn('good.png');
        $goodAsset->method('getLocalFile')->willReturn($good);

        $emptyAsset = $this->createMock(Asset::class);
        $emptyAsset->method('getFilename')->willReturn('empty.png');
        $emptyAsset->method('getLocalFile')->willReturn($empty);

        $result = $this->serviceWith([])->callBuild([$goodAsset, $emptyAsset]);

        try {
            self::assertSame(1, $result['added'], 'only the non-empty asset is packed');
            self::assertSame(1, $result['skipped'], 'the 0-byte asset is skipped, not packed empty');
            self::assertNotNull($result['path']);
        } finally {
            if ($result['path'] !== null) {
                @unlink($result['path']);
            }
            @unlink($good);
            @unlink($empty);
        }
    }
}
