<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(FailureReplayService::class)]
class FailureReplayServiceTest extends TestCase
{
    /**
     * @param array<array{object_id: int, object_class?: string}> $failed
     * @param array<int, ?AbstractObject>                          $objectsById
     */
    private function service(
        array $failed,
        AssetOrganizer $organizer,
        MessageBusInterface $bus,
        array $objectsById = [],
    ): FailureReplayService {
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->method('getDistinctFailedObjects')->willReturn($failed);

        return new class ($audit, $organizer, $bus, $objectsById) extends FailureReplayService {
            /** @param array<int, ?AbstractObject> $objectsById */
            public function __construct(AuditLoggerInterface $a, AssetOrganizer $o, MessageBusInterface $b, private array $objectsById)
            {
                parent::__construct($a, $o, $b, new NullLogger());
            }

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return $this->objectsById[$objectId] ?? null;
            }
        };
    }

    #[Test]
    public function syncReplaysEachExistingObjectAndSkipsMissing(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('organize')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $object = $this->createMock(AbstractObject::class);
        $service = $this->service(
            [['object_id' => 1, 'object_class' => 'Product'], ['object_id' => 2, 'object_class' => 'Product']],
            $organizer,
            $bus,
            [1 => $object], // object 2 no longer exists
        );

        $result = $service->replay();

        self::assertSame(2, $result->candidates);
        self::assertSame(1, $result->organized);
        self::assertSame(1, $result->skipped);
        self::assertSame(0, $result->dispatched);
        self::assertSame(0, $result->failed);
    }

    #[Test]
    public function asyncDispatchesEachCandidateWithoutLoading(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('organize');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnArgument(0);

        $service = $this->service(
            [['object_id' => 1], ['object_id' => 2]],
            $organizer,
            $bus,
        );

        $result = $service->replay(async: true);

        self::assertSame(2, $result->dispatched);
        self::assertSame(0, $result->organized);
    }

    #[Test]
    public function aFailedReorganizeIsCountedNotFatal(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organize')->willThrowException(new \RuntimeException('move failed again'));

        $object = $this->createMock(AbstractObject::class);
        $service = $this->service(
            [['object_id' => 1]],
            $organizer,
            $this->createMock(MessageBusInterface::class),
            [1 => $object],
        );

        $result = $service->replay();

        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->organized);
    }

    #[Test]
    public function aReorganizeThatReturnsAFailedResultCountsAsFailed(): void
    {
        $moveOp = new MoveOperation(1, '/a', '/b', 10, 'Product', 'r', OperationStatus::Failed, TriggerType::Manual);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organize')->willReturn([OperationResult::failed('still broken', $moveOp)]);

        $object = $this->createMock(AbstractObject::class);
        $result = $this->service([['object_id' => 1]], $organizer, $this->createMock(MessageBusInterface::class), [1 => $object])->replay();

        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->organized);
    }

    #[Test]
    public function noCandidatesYieldsAllZeroes(): void
    {
        $result = $this->service([], $this->createMock(AssetOrganizer::class), $this->createMock(MessageBusInterface::class))->replay();

        self::assertSame(0, $result->candidates);
        self::assertSame(0, $result->organized);
        self::assertSame(0, $result->dispatched);
    }
}
