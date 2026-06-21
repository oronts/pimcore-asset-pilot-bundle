<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Notification;

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
            public function notify(string $title, string $message): void
            {
                throw new \RuntimeException('transport down');
            }
        };
        $recording = new class ($received) implements NotifierInterface {
            public function __construct(private readonly \ArrayObject $received) {}

            public function notify(string $title, string $message): void
            {
                $this->received->append($title . '|' . $message);
            }
        };

        (new NotificationDispatcher([$throwing, $recording], new NullLogger(), enabled: true))->dispatch('Title', 'Body');

        // The throwing notifier did not stop the recording one.
        self::assertSame(['Title|Body'], $received->getArrayCopy());
    }

    #[Test]
    public function dispatchIsInertWhenDisabled(): void
    {
        $received = new \ArrayObject();
        $recording = new class ($received) implements NotifierInterface {
            public function __construct(private readonly \ArrayObject $received) {}

            public function notify(string $title, string $message): void
            {
                $this->received->append($title);
            }
        };

        $dispatcher = new NotificationDispatcher([$recording], new NullLogger());

        self::assertFalse($dispatcher->isEnabled());
        $dispatcher->dispatch('Title', 'Body');
        self::assertSame([], $received->getArrayCopy(), 'a disabled dispatcher must not touch any notifier');
    }
}
