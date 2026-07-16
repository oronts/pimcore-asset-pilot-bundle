<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Observer;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Oronts\AssetPilotBundle\Observer\DurableOperationObserverInterface;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationObserverRegistry::class)]
final class OperationObserverRegistryTest extends TestCase
{
    #[Test]
    public function preparesUniqueDeliveriesAndResolvesTheirObservers(): void
    {
        $first = $this->observer('first', [new PreparedDelivery('first', 'first:0', OperationDeliveryOutcome::Success)]);
        $second = $this->observer('second', [new PreparedDelivery('second', 'second:0', OperationDeliveryOutcome::Failure)]);
        $registry = new OperationObserverRegistry([$first, $second]);

        self::assertSame($first, $registry->get('first'));
        self::assertNull($registry->get('missing'));
        self::assertSame(['first:0', 'second:0'], array_map(
            static fn (PreparedDelivery $delivery): string => $delivery->deliveryKey,
            $registry->prepare($this->intent()),
        ));
    }

    #[Test]
    public function rejectsDuplicateObserverIds(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate durable operation observer ID "same".');

        new OperationObserverRegistry([$this->observer('same'), $this->observer('same')]);
    }

    #[Test]
    public function rejectsDuplicateDeliveryKeysAcrossObservers(): void
    {
        $registry = new OperationObserverRegistry([
            $this->observer('first', [new PreparedDelivery('first', 'shared', OperationDeliveryOutcome::Success)]),
            $this->observer('second', [new PreparedDelivery('second', 'shared', OperationDeliveryOutcome::Success)]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate durable delivery key "shared".');
        $registry->prepare($this->intent());
    }

    #[Test]
    public function rejectsAnObserverPreparingWorkForAnotherObserver(): void
    {
        $registry = new OperationObserverRegistry([
            $this->observer('first', [new PreparedDelivery('other', 'wrong-owner', OperationDeliveryOutcome::Success)]),
        ]);

        $this->expectException(\LogicException::class);
        $registry->prepare($this->intent());
    }

    /** @param list<PreparedDelivery> $deliveries */
    private function observer(string $id, array $deliveries = []): DurableOperationObserverInterface
    {
        return new class ($id, $deliveries) implements DurableOperationObserverInterface {
            /** @param list<PreparedDelivery> $deliveries */
            public function __construct(private readonly string $observerId, private readonly array $deliveries) {}

            public function id(): string
            {
                return $this->observerId;
            }

            public function requiredAssetPermission(): ?string
            {
                return null;
            }

            public function prepare(OperationIntent $intent): iterable
            {
                return $this->deliveries;
            }

            public function deliver(DeliveryEnvelope $delivery): void {}
        };
    }

    private function intent(): OperationIntent
    {
        return new OperationIntent(
            OperationKind::Move,
            1,
            '/source/a.jpg',
            '/target/a.jpg',
            2,
            'Product',
            'images',
            TriggerType::Manual,
            ActorContext::system(),
        );
    }
}
