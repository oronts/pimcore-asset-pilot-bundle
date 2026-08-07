<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Message;

use Oronts\AssetPilotBundle\Message\OperationDeliveryMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationDeliveryMessage::class)]
final class OperationDeliveryMessageTest extends TestCase
{
    #[Test]
    public function carriesTheExactStableDeliveryId(): void
    {
        self::assertSame('delivery-123', (new OperationDeliveryMessage('delivery-123'))->deliveryId);
    }

    #[Test]
    public function rejectsAnEmptyDeliveryId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OperationDeliveryMessage('');
    }
}
