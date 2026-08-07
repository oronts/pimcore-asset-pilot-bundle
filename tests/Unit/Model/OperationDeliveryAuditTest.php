<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Model\OperationDeliveryAudit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationDeliveryAudit::class)]
final class OperationDeliveryAuditTest extends TestCase
{
    /** @return iterable<string, array{OperationDeliveryStatus}> */
    public static function terminalStatuses(): iterable
    {
        yield 'dead' => [OperationDeliveryStatus::Dead];
        yield 'delivered' => [OperationDeliveryStatus::Delivered];
    }

    #[Test]
    #[DataProvider('terminalStatuses')]
    public function acceptsTerminalDeliveryState(OperationDeliveryStatus $status): void
    {
        $audit = new OperationDeliveryAudit('delivery', 42, 'observer', $status, 2, '2026-07-16 12:00:00');

        self::assertSame($status, $audit->status);
        self::assertSame(42, $audit->operationId);
    }

    #[Test]
    public function rejectsNonTerminalDeliveryState(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OperationDeliveryAudit('delivery', 42, 'observer', OperationDeliveryStatus::Retry, 2, '2026-07-16 12:00:00');
    }
}
