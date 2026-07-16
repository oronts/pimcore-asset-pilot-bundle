<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
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
    private function service(array $ids, array $assetsById, array $verdicts, bool $enabled = true, array $skip = ['svg'], array $hiddenAssets = []): object
    {
        $composite = $this->createMock(CompositeIntegrityChecker::class);
        $composite->method('check')->willReturnCallback(
            static fn (Asset $asset): IntegrityResult => new IntegrityResult($verdicts[spl_object_id($asset)] ?? IntegrityStatus::Renderable, 'stub'),
        );

        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset): bool => !in_array(spl_object_id($asset), $hiddenAssets, true),
        );

        return new class ($composite, new NullLogger(), $ids, $assetsById, $enabled, $skip, $authorization) extends AssetIntegrityService {
            /** @param list<int> $ids @param array<int, ?Asset> $assetsById */
            public function __construct($c, $log, private array $ids, private array $assetsById, bool $enabled, array $skip, ElementAuthorization $authorization)
            {
                parent::__construct($c, $log, $authorization, $enabled, $skip);
            }

            protected function listAssetIds(array $filters, int $offset, int $limit): array
            {
                return array_slice($this->ids, $offset, $limit);
            }

            /** @return array{string, list<string>} */
            public function exposedListingCondition(array $filters): array
            {
                return $this->listingCondition($filters);
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

    #[Test]
    public function hiddenWorkspaceAssetsAreNeverInspectedOrReturned(): void
    {
        $visible = $this->asset('/a/visible.jpg', 'visible.jpg');
        $hidden = $this->asset('/secret/hidden.jpg', 'hidden.jpg');
        $service = $this->service(
            [1, 2],
            [1 => $visible, 2 => $hidden],
            [spl_object_id($visible) => IntegrityStatus::Broken, spl_object_id($hidden) => IntegrityStatus::Broken],
            hiddenAssets: [spl_object_id($hidden)],
        );

        $result = $service->findBroken();

        self::assertSame(1, $result['scanned']);
        self::assertSame([1], array_column($result['items'], 'id'));
    }

    #[Test]
    public function folderRowsAreExcludedBeforePagination(): void
    {
        $service = $this->service([], [], []);

        [$condition, $params] = $service->exposedListingCondition([]);

        self::assertSame("type != 'folder'", $condition);
        self::assertSame([], $params);
    }

    #[Test]
    public function hasNextComesFromTheListingWindowNotTheScannedCount(): void
    {
        $skipped = $this->asset('/a/logo.svg', 'logo.svg');
        $first = $this->asset('/a/first.jpg', 'first.jpg');
        $next = $this->asset('/a/next.jpg', 'next.jpg');
        $service = $this->service(
            [1, 2, 3],
            [1 => $skipped, 2 => $first, 3 => $next],
            [],
        );

        $result = $service->findBroken([], 1, 2);

        self::assertSame(1, $result['scanned']);
        self::assertTrue($result['hasNext']);
    }
}
