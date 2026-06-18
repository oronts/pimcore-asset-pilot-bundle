<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LocationDriftService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(LocationDriftService::class)]
class LocationDriftServiceTest extends TestCase
{
    /** @param MoveOperation[] $dryRunOps */
    private function service(array $dryRunOps): LocationDriftService
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn($dryRunOps);

        return new LocationDriftService($organizer, new NullLogger());
    }

    private function op(int $assetId, string $from, string $to, OperationStatus $status): MoveOperation
    {
        return new MoveOperation($assetId, $from, $to, 10, 'Product', 'product_images', $status, TriggerType::Manual);
    }

    #[Test]
    public function driftForObjectReturnsOnlyTheWouldMoveOperations(): void
    {
        $service = $this->service([
            $this->op(1, '/old/a.jpg', '/new/a.jpg', OperationStatus::Pending),
            $this->op(2, '/x/b.jpg', '/x/b.jpg', OperationStatus::Skipped),
            $this->op(3, '/old/c.jpg', '/new/c.jpg', OperationStatus::Pending),
        ]);

        $drift = $service->driftForObject($this->createMock(AbstractObject::class));

        self::assertCount(2, $drift);
        self::assertSame(1, $drift[0]->assetId);
        self::assertSame('/old/a.jpg', $drift[0]->currentPath);
        self::assertSame('/new/a.jpg', $drift[0]->expectedPath);
        self::assertSame('product_images', $drift[0]->ruleName);
        self::assertSame(3, $drift[1]->assetId);
    }

    #[Test]
    public function noDriftWhenEverythingIsAtTarget(): void
    {
        $service = $this->service([
            $this->op(1, '/x/a.jpg', '/x/a.jpg', OperationStatus::Skipped),
        ]);

        self::assertSame([], $service->driftForObject($this->createMock(AbstractObject::class)));
    }

    #[Test]
    public function driftForObjectIdReturnsTheSameShapeAsAClassScan(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([
            $this->op(1, '/old/a.jpg', '/new/a.jpg', OperationStatus::Pending),
        ]);

        $service = new class ($organizer, $this->createMock(AbstractObject::class)) extends LocationDriftService {
            public function __construct(AssetOrganizer $organizer, private readonly AbstractObject $object)
            {
                parent::__construct($organizer, new NullLogger());
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->object;
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
        $organizer->expects(self::never())->method('dryRun');

        $service = new class ($organizer) extends LocationDriftService {
            public function __construct(AssetOrganizer $organizer)
            {
                parent::__construct($organizer, new NullLogger());
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return null;
            }
        };

        self::assertNull($service->driftForObjectId(999));
    }
}
