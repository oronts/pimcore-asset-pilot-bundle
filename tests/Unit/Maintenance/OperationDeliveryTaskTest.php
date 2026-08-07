<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Maintenance\OperationDeliveryTask;
use Oronts\AssetPilotBundle\Service\OperationDeliveryDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OperationDeliveryTask::class)]
final class OperationDeliveryTaskTest extends TestCase
{
    #[Test]
    public function pollsUsingTheConfiguredBatchSize(): void
    {
        $dispatcher = $this->createMock(OperationDeliveryDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatchDue')->with(37)->willReturn([
            'dispatched' => ['delivery'],
            'failed' => [],
        ]);

        (new OperationDeliveryTask($dispatcher, new NullLogger(), 37))->execute();
    }

    #[Test]
    public function doesNotBreakSharedMaintenanceWhenPollingFails(): void
    {
        $dispatcher = $this->createMock(OperationDeliveryDispatcherInterface::class);
        $dispatcher->method('dispatchDue')->willThrowException(new \RuntimeException('database unavailable'));

        (new OperationDeliveryTask($dispatcher, new NullLogger(), 37))->execute();

        $this->addToAssertionCount(1);
    }
}
