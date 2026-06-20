<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetZipService;
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
        };
    }
}
