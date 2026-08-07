<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Notification;

use Oronts\AssetPilotBundle\Enum\NotificationSeverity;
use Oronts\AssetPilotBundle\Notification\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Notification::class)]
final class NotificationTest extends TestCase
{
    #[Test]
    public function exposesImmutableTypedRoutingData(): void
    {
        $notification = new Notification('bulk.failure_rate', NotificationSeverity::Critical, 'Title', 'Body', [
            'failed' => 2,
            'total' => 3,
        ]);

        self::assertSame('bulk.failure_rate', $notification->kind);
        self::assertSame(NotificationSeverity::Critical, $notification->severity);
        self::assertSame(['failed' => 2, 'total' => 3], $notification->context);
        self::assertTrue((new \ReflectionClass($notification))->isReadOnly());
    }

    #[Test]
    public function rejectsAnUnstableRoutingKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Notification('Bulk failure', NotificationSeverity::Warning, 'Title', 'Body');
    }
}
