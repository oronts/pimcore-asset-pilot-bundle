<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\DriftEligibility;
use Oronts\AssetPilotBundle\Model\DriftItem;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LocationDriftService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(LocationDriftService::class)]
class LocationDriftServiceTest extends TestCase
{
    /** @param list<DriftItem> $items */
    private function service(array $items): LocationDriftService
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('analyzeDrift')->willReturn($items);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);

        return new LocationDriftService($organizer, $authorization);
    }

    private function item(int $assetId, string $from, string $to, DriftEligibility $eligibility = DriftEligibility::NoKnownBlock, ?string $reason = null): DriftItem
    {
        return new DriftItem($assetId, $from, $to, 'product_images', $eligibility, $reason);
    }

    #[Test]
    public function driftForObjectReturnsEveryMismatchIncludingBlockedAssets(): void
    {
        $service = $this->service([
            $this->item(1, '/old/a.jpg', '/new/a.jpg'),
            $this->item(3, '/old/c.jpg', '/new/c.jpg', DriftEligibility::Blocked, 'Asset is locked'),
        ]);

        $drift = $service->driftForObject($this->createMock(AbstractObject::class));

        self::assertCount(2, $drift);
        self::assertSame(1, $drift[0]->assetId);
        self::assertSame('/old/a.jpg', $drift[0]->currentPath);
        self::assertSame('/new/a.jpg', $drift[0]->expectedPath);
        self::assertSame('product_images', $drift[0]->ruleName);
        self::assertSame(3, $drift[1]->assetId);
        self::assertSame(DriftEligibility::Blocked, $drift[1]->eligibility);
        self::assertSame('Asset is locked', $drift[1]->reason);
    }

    #[Test]
    public function noDriftWhenEverythingIsAtTarget(): void
    {
        $service = $this->service([]);

        self::assertSame([], $service->driftForObject($this->createMock(AbstractObject::class)));
    }

    #[Test]
    public function driftForObjectIdReturnsTheSameShapeAsAClassScan(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('analyzeDrift')->willReturn([
            $this->item(1, '/old/a.jpg', '/new/a.jpg'),
        ]);

        $service = new class ($organizer, $this->createMock(ElementAuthorization::class), $this->createMock(AbstractObject::class)) extends LocationDriftService {
            public function __construct(AssetOrganizer $organizer, ElementAuthorization $authorization, private readonly AbstractObject $object)
            {
                parent::__construct($organizer, $authorization);
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->object;
            }

            protected function isVisible(AbstractObject $object): bool
            {
                return true;
            }
        };

        $result = $service->driftForObjectId(42);

        self::assertNotNull($result);
        self::assertCount(1, $result['items']);
        self::assertSame(1, $result['objectsScanned']);
        self::assertSame(1, $result['items'][0]->assetId);
    }

    #[Test]
    public function driftForObjectIdReturnsNullWhenTheObjectIsMissing(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('analyzeDrift');

        $service = new class ($organizer, $this->createMock(ElementAuthorization::class)) extends LocationDriftService {
            public function __construct(AssetOrganizer $organizer, ElementAuthorization $authorization)
            {
                parent::__construct($organizer, $authorization);
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return null;
            }
        };

        self::assertNull($service->driftForObjectId(999));
    }

    #[Test]
    public function driftForObjectIdHidesObjectsOutsideTheWorkspace(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('analyzeDrift');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);
        $object = $this->createMock(AbstractObject::class);

        $service = new class ($organizer, $authorization, $object) extends LocationDriftService {
            public function __construct(AssetOrganizer $organizer, ElementAuthorization $authorization, private readonly AbstractObject $object)
            {
                parent::__construct($organizer, $authorization);
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->object;
            }
        };

        self::assertNull($service->driftForObjectId(42));
    }
}
