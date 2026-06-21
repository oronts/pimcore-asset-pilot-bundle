<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(AssetIntegrityService::class)]
class AssetIntegrityServiceTest extends TestCase
{
    /**
     * @param list<int>                  $ids
     * @param array<int, ?Asset>         $assetsById
     * @param array<int, IntegrityStatus> $verdicts
     */
    private function service(array $ids, array $assetsById, array $verdicts, bool $enabled = true, array $skip = ['svg']): object
    {
        $composite = $this->createMock(CompositeIntegrityChecker::class);
        $composite->method('check')->willReturnCallback(
            static fn (Asset $asset): IntegrityResult => new IntegrityResult($verdicts[spl_object_id($asset)] ?? IntegrityStatus::Renderable, 'stub'),
        );

        return new class ($composite, new NullLogger(), $ids, $assetsById, $enabled, $skip) extends AssetIntegrityService {
            /** @param list<int> $ids @param array<int, ?Asset> $assetsById */
            public function __construct($c, $log, private array $ids, private array $assetsById, bool $enabled, array $skip)
            {
                parent::__construct($c, $log, $enabled, $skip);
            }

            protected function listAssetIds(array $filters, int $offset, int $limit): array
            {
                return array_slice($this->ids, $offset, $limit);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }
        };
    }

    private function asset(string $path, string $filename): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('getFilename')->willReturn($filename);

        return $asset;
    }

    #[Test]
    public function findBrokenReturnsOnlyTheBrokenAssets(): void
    {
        $good = $this->asset('/a/good.jpg', 'good.jpg');
        $bad = $this->asset('/a/bad.jpg', 'bad.jpg');

        $service = $this->service(
            [1, 2],
            [1 => $good, 2 => $bad],
            [spl_object_id($good) => IntegrityStatus::Renderable, spl_object_id($bad) => IntegrityStatus::Broken],
        );

        $result = $service->findBroken([], 1, 50);

        self::assertSame(2, $result['scanned']);
        self::assertSame(1, $result['broken']);
        self::assertSame(2, $result['items'][0]['id']);
    }

    #[Test]
    public function findBrokenSkipsConfiguredExtensions(): void
    {
        $svg = $this->asset('/a/logo.svg', 'logo.svg');

        $service = $this->service([1], [1 => $svg], [spl_object_id($svg) => IntegrityStatus::Broken]);

        $result = $service->findBroken([], 1, 50);

        self::assertSame(0, $result['scanned']);
        self::assertSame(0, $result['broken']);
    }

    #[Test]
    public function checkAssetsInspectsExactlyTheRequestedIds(): void
    {
        $good = $this->asset('/a/good.jpg', 'good.jpg');
        $bad = $this->asset('/a/bad.jpg', 'bad.jpg');

        $service = $this->service(
            [],
            [7 => $good, 8 => $bad, 9 => null],
            [spl_object_id($good) => IntegrityStatus::Renderable, spl_object_id($bad) => IntegrityStatus::Broken],
        );

        $result = $service->checkAssets([7, 8, 9]);

        self::assertSame(2, $result['scanned']); // id 9 is gone
        self::assertSame(1, $result['broken']);
        self::assertSame(8, $result['items'][0]['id']);
    }

    #[Test]
    public function findBrokenIsANoOpWhenDisabled(): void
    {
        $bad = $this->asset('/a/bad.jpg', 'bad.jpg');

        $service = $this->service([1], [1 => $bad], [spl_object_id($bad) => IntegrityStatus::Broken], enabled: false);

        self::assertSame(0, $service->findBroken([], 1, 50)['scanned']);
    }
}
