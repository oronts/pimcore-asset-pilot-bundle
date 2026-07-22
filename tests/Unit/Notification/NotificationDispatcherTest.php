<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Notification;

use Oronts\AssetPilotBundle\Enum\NotificationSeverity;
use Oronts\AssetPilotBundle\Notification\Notification;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcher;
use Oronts\AssetPilotBundle\Notification\NotifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(NotificationDispatcher::class)]
class NotificationDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchesToEveryNotifierAndIsolatesAFailingOne(): void
    {
        $received = new \ArrayObject();

        $throwing = new class () implements NotifierInterface {
            public function notify(Notification $notification): void
            {
                throw new \RuntimeException('transport down');
            }
        };
        $recording = new class ($received) implements NotifierInterface {
            public function __construct(private readonly \ArrayObject $received) {}

            public function notify(Notification $notification): void
            {
                $this->received->append($notification);
            }
        };

        $notification = new Notification('test.alert', NotificationSeverity::Warning, 'Title', 'Body', ['runId' => 'run-7']);
        (new NotificationDispatcher([$throwing, $recording], new NullLogger(), enabled: true))->dispatch($notification);

        self::assertSame([$notification], $received->getArrayCopy());
    }

    #[Test]
    public function dispatchIsInertWhenDisabled(): void
    {
        $received = new \ArrayObject();
        $recording = new class ($received) implements NotifierInterface {
            public function __construct(private readonly \ArrayObject $received) {}

            public function notify(Notification $notification): void
            {
                $this->received->append($notification->title);
            }
        };

        $dispatcher = new NotificationDispatcher([$recording], new NullLogger());

        self::assertFalse($dispatcher->isEnabled());
        $dispatcher->dispatch(new Notification('test.alert', NotificationSeverity::Warning, 'Title', 'Body', ['runId' => 'run-7']));
        self::assertSame([], $received->getArrayCopy(), 'a disabled dispatcher must not touch any notifier');
    }
}
