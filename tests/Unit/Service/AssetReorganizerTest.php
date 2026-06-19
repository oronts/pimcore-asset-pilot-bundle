<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(AssetReorganizer::class)]
class AssetReorganizerTest extends TestCase
{
    /**
     * @param list<int>            $assetIds       returned by the folder-listing seam
     * @param array<int, list<int>> $ownersByAsset  dependent object ids per asset id
     * @param array<int, ?AbstractObject> $objectsById
     */
    private function reorganizer(
        array $assetIds,
        array $ownersByAsset,
        AssetOrganizer $organizer,
        OrganizeDispatcher $bus,
        array $objectsById = [],
    ): AssetReorganizer {
        $resolver = $this->createMock(AssetDependencyResolver::class);
        $resolver->method('dependentObjectIds')->willReturnCallback(
            static fn (int $assetId): array => $ownersByAsset[$assetId] ?? [],
        );

        return new class ($resolver, $organizer, $bus, $assetIds, $objectsById) extends AssetReorganizer {
            /** @param list<int> $assetIds @param array<int, ?AbstractObject> $objectsById */
            public function __construct(AssetDependencyResolver $r, AssetOrganizer $o, OrganizeDispatcher $b, private array $assetIds, private array $objectsById)
            {
                parent::__construct($r, $o, $b, new NullLogger());
            }

            protected function listAssetIdsInFolder(string $folderPath, int $limit): array
            {
                return array_slice($this->assetIds, 0, $limit);
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->objectsById[$id] ?? null;
            }
        };
    }

    #[Test]
    public function organizesEachDistinctOwnerObjectOnce(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        // assets 1,2 both owned by object 10; asset 2 also owned by 20 -> organize 10 and 20 once each.
        $organizer->expects(self::exactly(2))->method('organize')->willReturn([]);

        $bus = $this->createMock(OrganizeDispatcher::class);
        $bus->expects(self::never())->method('dispatchObject');

        $object = $this->createMock(AbstractObject::class);
        $result = $this->reorganizer(
            [1, 2],
            [1 => [10], 2 => [10, 20]],
            $organizer,
            $bus,
            [10 => $object, 20 => $object],
        )->reorganizeFolder('/Staging');

        self::assertSame(2, $result->assetsScanned);
        self::assertSame(2, $result->ownerObjects);
        self::assertSame(2, $result->organized);
        self::assertSame(0, $result->dispatched);
    }

    #[Test]
    public function asyncDispatchesEachOwnerWithoutLoading(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organize');

        $bus = $this->createMock(OrganizeDispatcher::class);
        $bus->expects(self::exactly(2))->method('dispatchObject');

        $result = $this->reorganizer([1], [1 => [10, 20]], $organizer, $bus)->reorganizeFolder('/Staging', 50, true);

        self::assertSame(2, $result->dispatched);
        self::assertSame(0, $result->organized);
    }

    #[Test]
    public function anAsyncDispatchFailureIsCountedNotFatal(): void
    {
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('dispatchObject')->willThrowException(new \RuntimeException('bus down'));

        $result = $this->reorganizer([1], [1 => [10]], $this->createMock(AssetOrganizer::class), $dispatcher)
            ->reorganizeFolder('/Staging', 50, true);

        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->dispatched);
    }

    #[Test]
    public function skipsOwnersThatNoLongerExist(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $result = $this->reorganizer([1], [1 => [10]], $organizer, $this->createMock(OrganizeDispatcher::class), [])->reorganizeFolder('/Staging');

        self::assertSame(1, $result->ownerObjects);
        self::assertSame(1, $result->skipped);
        self::assertSame(0, $result->organized);
    }

    #[Test]
    public function reorganizeAssetsOrganizesTheOwnersOfTheGivenAssetIds(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        // assets 1,2 owned by 10; asset 2 also owned by 20 -> organize 10 and 20 once each.
        $organizer->expects(self::exactly(2))->method('organize')->willReturn([]);

        $bus = $this->createMock(OrganizeDispatcher::class);
        $bus->expects(self::never())->method('dispatchObject');

        $object = $this->createMock(AbstractObject::class);
        $result = $this->reorganizer(
            [],
            [1 => [10], 2 => [10, 20]],
            $organizer,
            $bus,
            [10 => $object, 20 => $object],
        )->reorganizeAssets([1, 2]);

        self::assertSame(2, $result->assetsScanned);
        self::assertSame(2, $result->ownerObjects);
        self::assertSame(2, $result->organized);
    }

    #[Test]
    public function reorganizeAssetsDedupesAndDropsNonPositiveIds(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('organize')->willReturn([]);

        $object = $this->createMock(AbstractObject::class);
        $result = $this->reorganizer([], [5 => [10]], $organizer, $this->createMock(OrganizeDispatcher::class), [10 => $object])
            ->reorganizeAssets([5, 5, 0, -3]);

        self::assertSame(1, $result->assetsScanned);
        self::assertSame(1, $result->ownerObjects);
        self::assertSame(1, $result->organized);
    }
}
