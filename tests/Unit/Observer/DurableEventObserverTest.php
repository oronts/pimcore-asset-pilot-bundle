<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Observer;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\DurableOperationEvent;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Observer\DurableEventObserver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(DurableEventObserver::class)]
#[CoversClass(DurableOperationEvent::class)]
final class DurableEventObserverTest extends TestCase
{
    #[Test]
    public function preparesBothOutcomesAndDispatchesTheActivatedOneWithItsStableId(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $observer = new DurableEventObserver($dispatcher);
        self::assertNull($observer->requiredAssetPermission());
        $intent = new OperationIntent(
            OperationKind::Move,
            7,
            '/source/a.jpg',
            '/target/a.jpg',
            9,
            'Product',
            'images',
            TriggerType::Manual,
            ActorContext::system(),
        );
        $prepared = [...$observer->prepare($intent)];
        self::assertSame(
            [OperationDeliveryOutcome::Success, OperationDeliveryOutcome::Failure],
            array_map(static fn ($delivery) => $delivery->outcome, $prepared),
        );

        $envelope = new DeliveryEnvelope(
            str_repeat('b', 64),
            8,
            $prepared[0]->deliveryKey,
            $observer->id(),
            OperationDeliveryOutcome::Success,
            $intent,
            [],
            1,
            'worker',
        );
        $dispatcher->expects(self::once())->method('dispatch')->with(
            self::callback(static fn (DurableOperationEvent $event): bool => $event->delivery === $envelope),
            AssetPilotEvents::DURABLE_OPERATION_SUCCEEDED,
        );

        $observer->deliver($envelope);
    }
}
