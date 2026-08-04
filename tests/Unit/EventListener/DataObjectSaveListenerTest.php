<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\EventListener\DataObjectSaveListener;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStoreInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;

#[CoversClass(DataObjectSaveListener::class)]
class DataObjectSaveListenerTest extends TestCase
{
    private AssetOrganizer $organizer;
    private OrganizeDispatcher $dispatcher;
    private LoopGuard $loopGuard;
    private AutomaticOrganizeIntentStoreInterface $intents;

    protected function setUp(): void
    {
        $this->organizer = $this->createMock(AssetOrganizer::class);
        $this->dispatcher = $this->createMock(OrganizeDispatcher::class);
        $this->loopGuard = $this->createMock(LoopGuard::class);
        $this->intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
    }

    private function createListener(
        bool $enabled = true,
        array $allowedClasses = [],
        bool $asyncEnabled = true,
        int $transactionNesting = 0,
    ): DataObjectSaveListener {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn($transactionNesting);

        return new DataObjectSaveListener(
            organizer: $this->organizer,
            dispatcher: $this->dispatcher,
            loopGuard: $this->loopGuard,
            logger: new NullLogger(),
            connection: $connection,
            intents: $this->intents,
            enabled: $enabled,
            allowedClasses: $allowedClasses,
            asyncEnabled: $asyncEnabled,
        );
    }

    private function createEvent(AbstractObject $object): DataObjectEvent
    {
        return new DataObjectEvent($object);
    }

    #[Test]
    public function doesNothingWhenDisabled(): void
    {
        $listener = $this->createListener(enabled: false);
        $object = $this->createMock(Concrete::class);

        $this->dispatcher->expects(self::never())->method('deferObject');
        $this->organizer->expects(self::never())->method('organize');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function doesNothingForNonConcreteObjects(): void
    {
        $listener = $this->createListener();
        $object = $this->createMock(AbstractObject::class);

        $this->dispatcher->expects(self::never())->method('deferObject');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function skipsClassNotInAllowedList(): void
    {
        $listener = $this->createListener(allowedClasses: ['Category']);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');

        $this->dispatcher->expects(self::never())->method('deferObject');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function allowsClassInAllowedList(): void
    {
        $listener = $this->createListener(allowedClasses: ['Product', 'Category']);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->dispatcher->expects(self::once())->method('deferObject');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function allowsAnyClassWhenAllowedListEmpty(): void
    {
        $listener = $this->createListener(allowedClasses: []);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('AnyClass');
        $object->method('getId')->willReturn(1);

        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->dispatcher->expects(self::once())->method('deferObject');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function skipsWhenObjectIsBeingProcessed(): void
    {
        $listener = $this->createListener();

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->with(42)->willReturn(true);
        $this->loopGuard->expects(self::once())->method('markObjectDirty')->with(42);
        $this->dispatcher->expects(self::never())->method('deferObject');
        $this->organizer->expects(self::never())->method('organize');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function dispatchesAsyncMessageWhenAsyncEnabled(): void
    {
        $listener = $this->createListener(asyncEnabled: true);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->dispatcher->expects(self::once())
            ->method('deferObject')
            ->with(42, TriggerType::ObjectSave);
        $this->loopGuard->expects(self::once())->method('markObjectDispatched')->with(42);

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function doesNotMarkDispatchedWhileInsideAConsumerTransaction(): void
    {
        $listener = $this->createListener(asyncEnabled: true, transactionNesting: 1);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->dispatcher->expects(self::once())->method('deferObject')->with(42, TriggerType::ObjectSave);
        $this->loopGuard->expects(self::never())->method('markObjectDispatched');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function coalescesIntoTheInFlightRunWhenOneIsActive(): void
    {
        $listener = $this->createListener(asyncEnabled: true);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->with(42)->willReturn(false);
        $this->loopGuard->method('tryCoalesceIntoInFlightRun')->with(42)->willReturn(true);
        $this->dispatcher->expects(self::never())->method('deferObject');
        $this->intents->expects(self::once())->method('markDirtyIfPresent')->with(42);

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function propagatesTheOutboxFailureInsideACallerOwnedTransaction(): void
    {
        $listener = $this->createListener(asyncEnabled: true, transactionNesting: 1);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);
        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->loopGuard->method('tryCoalesceIntoInFlightRun')->willReturn(false);
        $this->dispatcher->method('deferObject')->willThrowException(new \RuntimeException('outbox write failed'));

        // The save has not committed yet, so the failure must roll back with it, not be swallowed.
        $this->expectException(\RuntimeException::class);
        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function swallowsTheOutboxFailureAfterTheSaveHasCommitted(): void
    {
        $listener = $this->createListener(asyncEnabled: true, transactionNesting: 0);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);
        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->loopGuard->method('tryCoalesceIntoInFlightRun')->willReturn(false);
        $this->dispatcher->method('deferObject')->willThrowException(new \RuntimeException('outbox write failed'));

        $listener->onPostUpdate($this->createEvent($object));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function recordsItsOwnRunWhenThereIsNoInFlightRunToCoalesceInto(): void
    {
        $listener = $this->createListener(asyncEnabled: true);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->with(42)->willReturn(false);
        $this->loopGuard->method('tryCoalesceIntoInFlightRun')->with(42)->willReturn(false);
        $this->dispatcher->expects(self::once())->method('deferObject')->with(42, TriggerType::ObjectSave);
        $this->loopGuard->expects(self::once())->method('markObjectDispatched')->with(42);

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function callsOrganizerSyncWhenAsyncDisabled(): void
    {
        $listener = $this->createListener(asyncEnabled: false);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->organizer->expects(self::once())
            ->method('organize')
            ->with($object, TriggerType::ObjectSave)
            ->willReturn([]);

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function syncErrorDoesNotThrow(): void
    {
        $listener = $this->createListener(asyncEnabled: false);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(42);

        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->organizer->method('organize')->willThrowException(new \RuntimeException('fail'));

        $listener->onPostUpdate($this->createEvent($object));
        self::assertTrue(true);
    }

    #[Test]
    public function onPostAddUsesObjectSaveTrigger(): void
    {
        $listener = $this->createListener(asyncEnabled: true);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(10);

        $this->loopGuard->method('isProcessingObject')->willReturn(false);
        $this->dispatcher->expects(self::once())
            ->method('deferObject')
            ->with(10, TriggerType::ObjectSave);

        $listener->onPostAdd($this->createEvent($object));
    }
}
