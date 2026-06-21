<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\EventListener\DataObjectSaveListener;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
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

    protected function setUp(): void
    {
        $this->organizer = $this->createMock(AssetOrganizer::class);
        $this->dispatcher = $this->createMock(OrganizeDispatcher::class);
        $this->loopGuard = $this->createMock(LoopGuard::class);
    }

    private function createListener(
        bool $enabled = true,
        array $allowedClasses = [],
        bool $asyncEnabled = true,
    ): DataObjectSaveListener {
        return new DataObjectSaveListener(
            organizer: $this->organizer,
            dispatcher: $this->dispatcher,
            loopGuard: $this->loopGuard,
            logger: new NullLogger(),
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

        $this->dispatcher->expects(self::never())->method('dispatchObject');
        $this->organizer->expects(self::never())->method('organize');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function doesNothingForNonConcreteObjects(): void
    {
        $listener = $this->createListener();
        $object = $this->createMock(AbstractObject::class);

        $this->dispatcher->expects(self::never())->method('dispatchObject');

        $listener->onPostUpdate($this->createEvent($object));
    }

    #[Test]
    public function skipsClassNotInAllowedList(): void
    {
        $listener = $this->createListener(allowedClasses: ['Category']);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');

        $this->dispatcher->expects(self::never())->method('dispatchObject');

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
        $this->dispatcher->expects(self::once())->method('dispatchObject');

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
        $this->dispatcher->expects(self::once())->method('dispatchObject');

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
        $this->dispatcher->expects(self::never())->method('dispatchObject');
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
            ->method('dispatchObject')
            ->with(42, TriggerType::ObjectSave);

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
            ->method('dispatchObject')
            ->with(10, TriggerType::ObjectSave);

        $listener->onPostAdd($this->createEvent($object));
    }
}
