<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetZipService;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipBuildResult;
use Oronts\AssetPilotBundle\Zip\ZipEntryStrategyInterface;
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
    public function usesTheExplicitActorForPerAssetAuthorization(): void
    {
        $actor = ActorContext::user(7);
        $allowed = $this->createMock(Asset::class);
        $allowed->expects(self::never())->method('isAllowed');
        $denied = $this->createMock(Asset::class);
        $denied->expects(self::never())->method('isAllowed');
        $folder = $this->createMock(Asset\Folder::class);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->expects(self::exactly(2))
            ->method('isAllowed')
            ->with(self::isInstanceOf(Asset::class), 'view', $actor)
            ->willReturnOnConsecutiveCalls(true, false);

        $service = $this->serviceWith([1 => $allowed, 2 => $denied, 3 => $folder, 4 => null], authorization: $authorization);

        self::assertSame([$allowed], $service->downloadable([1, 2, 3, 4], $actor));
    }

    #[Test]
    public function rejectsRequestsAboveMaxAssets(): void
    {
        $assets = [];
        for ($id = 1; $id <= 5; $id++) {
            $a = $this->createMock(Asset::class);
            $a->method('isAllowed')->willReturn(true);
            $assets[$id] = $a;
        }

        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('configured limit is 2');

        $this->serviceWith($assets, 2)->downloadable([1, 2, 3, 4, 5]);
    }

    #[Test]
    public function rejectsDuplicateStrategyNames(): void
    {
        $strategy = static function (): ZipEntryStrategyInterface {
            return new class () implements ZipEntryStrategyInterface {
                public function getName(): string
                {
                    return 'folder';
                }

                public function entryPath(Asset $asset): string
                {
                    return $asset->getFilename();
                }
            };
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate ZIP strategy alias "folder".');

        new AssetZipService(new NullLogger(), $this->createMock(AssetFieldExtractorInterface::class), $this->createMock(ElementAuthorization::class), [$strategy(), $strategy()]);
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
    private function serviceWith(array $map, int $maxAssets = 1000, int $maxBytes = 536870912, ?ElementAuthorization $authorization = null): object
    {
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        if ($authorization === null) {
            $authorization = $this->createMock(ElementAuthorization::class);
            $authorization->method('isAllowed')->willReturn(true);
        }

        return new class (new NullLogger(), $extractor, $authorization, $map, $maxAssets, $maxBytes) extends AssetZipService {
            /** @param array<int, ?Asset> $map */
            public function __construct(NullLogger $logger, AssetFieldExtractorInterface $extractor, ElementAuthorization $authorization, private readonly array $map, int $maxAssets, int $maxBytes)
            {
                parent::__construct($logger, $extractor, $authorization, [], 'flat', $maxAssets, $maxBytes);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->map[$id] ?? null;
            }

            /**
             * @param int[] $ids
             * @return Asset[]
             */
            public function downloadable(array $ids, ?ActorContext $actor = null): array
            {
                return $this->downloadableAssets($ids, $actor);
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

            /** @param list<Asset> $assets */
            public function callBuild(array $assets, ?ZipBuildOptions $options = null): ZipBuildResult
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
            self::assertSame(1, $result->added, 'only the non-empty asset is packed');
            self::assertSame(1, $result->skipped, 'the 0-byte asset is skipped, not packed empty');
            self::assertSame(2, $result->requested);
            self::assertFalse($result->truncated);
            self::assertNotNull($result->path);
        } finally {
            if ($result->path !== null) {
                @unlink($result->path);
            }
            @unlink($good);
            @unlink($empty);
        }
    }

    #[Test]
    public function buildRejectsAssetsAboveTheUncompressedByteBudget(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'apz_limit_');
        file_put_contents($file, '123456');
        $asset = $this->createMock(Asset::class);
        $asset->method('getFilename')->willReturn('large.bin');
        $asset->method('getLocalFile')->willReturn($file);

        try {
            $this->expectException(\LengthException::class);
            $this->expectExceptionMessage('5-byte uncompressed limit');
            $this->serviceWith([], maxBytes: 5)->callBuild([$asset]);
        } finally {
            @unlink($file);
        }
    }
    #[Test]
    public function buildRemovesTheArchiveWhenEveryAssetIsSkipped(): void
    {
        $empty = (string) tempnam(sys_get_temp_dir(), 'apz_empty_');
        $asset = $this->createMock(Asset::class);
        $asset->method('getFilename')->willReturn('empty.png');
        $asset->method('getLocalFile')->willReturn($empty);

        try {
            self::assertEquals(
                new ZipBuildResult(null, 1, 0, 1),
                $this->serviceWith([])->callBuild([$asset]),
            );
        } finally {
            @unlink($empty);
        }
    }

}
