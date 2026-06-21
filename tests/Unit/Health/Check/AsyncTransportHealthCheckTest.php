<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\AsyncTransportHealthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsyncTransportHealthCheck::class)]
class AsyncTransportHealthCheckTest extends TestCase
{
    #[Test]
    public function okAndInformationalWhenSynchronous(): void
    {
        $result = (new AsyncTransportHealthCheck(false, 50))->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertFalse($result->details['async_enabled']);
    }

    #[Test]
    public function okWithConsumerReminderWhenAsyncEnabled(): void
    {
        $result = (new AsyncTransportHealthCheck(true, 100))->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertTrue($result->details['async_enabled']);
        self::assertSame(100, $result->details['batch_size']);
        self::assertStringContainsString('messenger:consume', $result->message);
    }
}
