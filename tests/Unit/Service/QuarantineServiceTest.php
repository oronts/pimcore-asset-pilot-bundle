<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(QuarantineService::class)]
class QuarantineServiceTest extends TestCase
{
    /**
     * @param array<int, ?Asset>  $assetsById
     * @param array<int, ?string> $originalPaths
     */
    private function service(
        array $assetsById,
        array $originalPaths = [],
        ?LoopGuard $loopGuard = null,
        bool $isReferenced = false,
        bool $allowCreate = true,
    ): object {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('isReferenced')->willReturn($isReferenced);

        return new class (
            $this->createMock(Connection::class),
            $loopGuard ?? $this->createMock(LoopGuard::class),
            $finder,
            new EventDispatcher(),
            new NullLogger(),
            $assetsById,
            $originalPaths,
            $allowCreate,
        ) extends QuarantineService {
            public array $recorded = [];
            public array $removed = [];

            /** @param array<int, ?Asset> $assetsById @param array<int, ?string> $originalPaths */
            public function __construct(Connection $c, LoopGuard $lg, UnusedAssetFinderInterface $f, $ed, $log, private array $assetsById, private array $originalPaths, private bool $allowCreate)
            {
                parent::__construct($c, $lg, $f, $ed, $log);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }

            protected function resolveFolder(string $path): Asset\Folder
            {
                return new Asset\Folder();
            }

            protected function targetAllowsCreate(string $path): bool
            {
                return $this->allowCreate;
            }

            protected function recordQuarantine(int $assetId, string $originalPath): void
            {
                $this->recorded[$assetId] = $originalPath;
            }

            protected function findOriginalPath(int $assetId): ?string
            {
                return $this->originalPaths[$assetId] ?? null;
            }

            protected function deleteQuarantineRecord(int $assetId): void
            {
                $this->removed[] = $assetId;
            }
        };
    }

    private function asset(string $path, bool $allowed = true): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('isAllowed')->willReturn($allowed);

        return $asset;
    }

    #[Test]
    public function quarantinesExistingUnusedAssetsAndRecordsTheirOrigin(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg'), 2 => null]);

        $result = $service->quarantine([1, 2]);

        self::assertSame(1, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertSame('/Products/a.jpg', $service->recorded[1]);
    }

    #[Test]
    public function skipsAssetsThatBecameReferenced(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg')], isReferenced: true);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
        self::assertArrayNotHasKey(1, $service->recorded);
    }

    #[Test]
    public function skipsAssetsTheUserMayNotMove(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg', allowed: false)]);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function failsAllWhenTheQuarantineFolderIsNotWritable(): void
    {
        $service = $this->service([1 => $this->asset('/Products/a.jpg')], allowCreate: false);

        $result = $service->quarantine([1]);

        self::assertSame(0, $result['quarantined']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function restoreMovesBackAndClearsTheRecord(): void
    {
        $service = $this->service([5 => $this->asset('/Quarantine/a.jpg')], [5 => '/Products/a.jpg']);

        self::assertTrue($service->restore(5));
        self::assertSame([5], $service->removed);
    }

    #[Test]
    public function restoreReturnsFalseWhenNothingIsQuarantined(): void
    {
        $service = $this->service([5 => $this->asset('/x')], []);

        self::assertFalse($service->restore(5));
        self::assertSame([], $service->removed);
    }

    #[Test]
    public function theQuarantineMoveFollowsTheLoopGuardOrdering(): void
    {
        $calls = new \ArrayObject();
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('markAssetProcessing')->willReturnCallback(static fn () => $calls->append('mark'));
        $loopGuard->method('markAssetRecentlyMoved')->willReturnCallback(static fn () => $calls->append('recentlyMoved'));
        $loopGuard->method('unmarkAssetProcessing')->willReturnCallback(static fn () => $calls->append('unmark'));

        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn('/Products/a.jpg');
        $asset->method('isAllowed')->willReturn(true);
        $asset->method('save')->willReturnCallback(function () use (&$asset, $calls) {
            $calls->append('save');

            return $asset;
        });

        $this->service([1 => $asset], loopGuard: $loopGuard)->quarantine([1]);

        self::assertSame(['mark', 'save', 'recentlyMoved', 'unmark'], $calls->getArrayCopy());
    }
}
