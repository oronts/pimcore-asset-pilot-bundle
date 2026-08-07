<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Event;

use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

#[CoversClass(NonFatalEventDispatcher::class)]
class NonFatalEventDispatcherTest extends TestCase
{
    #[Test]
    public function continuesWithLaterObserversAfterOneFails(): void
    {
        $dispatcher = new EventDispatcher();
        $called = false;
        $dispatcher->addListener('completed', static function (): void {
            throw new \RuntimeException('Observer failed.');
        }, 10);
        $dispatcher->addListener('completed', static function () use (&$called): void {
            $called = true;
        });

        $errors = NonFatalEventDispatcher::dispatch(
            $dispatcher,
            new Event(),
            'completed',
            new NullLogger(),
        );

        self::assertTrue($called);
        self::assertSame(['Observer failed.'], $errors);
    }
}
