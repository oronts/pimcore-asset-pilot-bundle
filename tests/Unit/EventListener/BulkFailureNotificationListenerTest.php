<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\EventListener\BulkFailureNotificationListener;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BulkFailureNotificationListener::class)]
class BulkFailureNotificationListenerTest extends TestCase
{
    private function operationResult(OperationStatus $status): OperationResult
    {
        $operation = new MoveOperation(1, '/a', '/b', 10, 'Product', 'r', $status, TriggerType::BulkOperation);

        return match ($status) {
            OperationStatus::Failed => OperationResult::failed('failed', $operation),
            OperationStatus::Completed => OperationResult::success($operation),
            default => OperationResult::skipped('skipped', $operation),
        };
    }

    /** @param OperationResult[] $results */
    private function event(array $results): BulkOrganizeEvent
    {
        return new BulkOrganizeEvent([1, 2], TriggerType::BulkOperation, $results);
    }

    #[Test]
    public function notifiesWhenTheFailureRateMeetsTheThreshold(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch');

        // 2 of 3 failed = 0.67 >= 0.5
        (new BulkFailureNotificationListener($dispatcher, enabled: true, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([
                $this->operationResult(OperationStatus::Failed),
                $this->operationResult(OperationStatus::Failed),
                $this->operationResult(OperationStatus::Completed),
            ]));
    }

    #[Test]
    public function staysSilentBelowTheThreshold(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        // 1 of 3 failed = 0.33 < 0.5
        (new BulkFailureNotificationListener($dispatcher, enabled: true, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([
                $this->operationResult(OperationStatus::Failed),
                $this->operationResult(OperationStatus::Completed),
                $this->operationResult(OperationStatus::Completed),
            ]));
    }

    #[Test]
    public function staysSilentWhenDisabled(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        (new BulkFailureNotificationListener($dispatcher, enabled: false, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([$this->operationResult(OperationStatus::Failed)]));
    }

    #[Test]
    public function staysSilentWhenThereAreNoResults(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        (new BulkFailureNotificationListener($dispatcher, enabled: true, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([]));
    }
}
