<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\OperationDeliveryMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Oronts\AssetPilotBundle\Service\OperationDeliveryDispatcher;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStore;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(OperationDeliveryDispatcher::class)]
final class OperationDeliveryDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchesEveryExactDueIdAndForwardsTheScanLimit(): void
    {
        $store = $this->createMock(OperationDeliveryStoreInterface::class);
        $store->expects(self::once())->method('due')->with(25)->willReturn(['first', 'second']);
        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (OperationDeliveryMessage $message) use (&$messages): Envelope {
                $messages[] = $message;

                return new Envelope($message);
            },
        );

        $result = (new OperationDeliveryDispatcher($store, $bus, new NullLogger()))->dispatchDue(25);

        self::assertSame(['first', 'second'], array_map(
            static fn (OperationDeliveryMessage $message): string => $message->deliveryId,
            $messages,
        ));
        self::assertSame(['dispatched' => ['first', 'second'], 'failed' => []], $result);
    }

    #[Test]
    public function brokerFailureLeavesTheRowDueForTheNextPoll(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        OperationDeliveryStoreTest::createTable($connection);
        $store = new OperationDeliveryStore($connection);
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
        $id = $store->prepare(new OperationHandle(70, $intent), [
            new PreparedDelivery('observer', 'observer:0', OperationDeliveryOutcome::Success),
        ])[0];
        $store->activateForOutcome(70, OperationDeliveryOutcome::Success);

        $attempt = 0;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (OperationDeliveryMessage $message) use (&$attempt): Envelope {
                ++$attempt;
                if ($attempt === 1) {
                    throw new \RuntimeException('broker unavailable');
                }

                return new Envelope($message);
            },
        );
        $dispatcher = new OperationDeliveryDispatcher($store, $bus, new NullLogger());

        self::assertSame(['dispatched' => [], 'failed' => [$id]], $dispatcher->dispatchDue());
        self::assertSame([$id], $store->due());
        self::assertSame(['dispatched' => [$id], 'failed' => []], $dispatcher->dispatchDue());
        self::assertSame([$id], $store->due());

        $connection->close();
    }

    #[Test]
    public function oneBrokerFailureDoesNotPreventLaterDueIdsFromBeingQueued(): void
    {
        $store = $this->createMock(OperationDeliveryStoreInterface::class);
        $store->method('due')->willReturn(['first', 'second']);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (OperationDeliveryMessage $message): Envelope {
                if ($message->deliveryId === 'first') {
                    throw new \RuntimeException('first failed');
                }

                return new Envelope($message);
            },
        );

        self::assertSame(
            ['dispatched' => ['second'], 'failed' => ['first']],
            (new OperationDeliveryDispatcher($store, $bus, new NullLogger()))->dispatchDue(),
        );
    }
}
