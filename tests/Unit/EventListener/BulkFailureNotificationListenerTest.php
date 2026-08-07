<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\NotificationSeverity;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\EventListener\BulkFailureNotificationListener;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Notification\Notification;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcherInterface;
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

    /**
     * @param list<OperationResult>  $results
     * @param list<BulkObjectResult> $objectResults
     */
    private function event(array $results, array $objectResults = []): BulkOrganizeEvent
    {
        return new BulkOrganizeEvent([1, 2], TriggerType::BulkOperation, $results, $objectResults);
    }

    #[Test]
    public function notifiesWhenTheFailureRateMeetsTheThreshold(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->method('isEnabled')->willReturn(true);
        $dispatcher->expects(self::once())->method('dispatch');

        // 2 of 3 failed = 0.67 >= 0.5
        (new BulkFailureNotificationListener($dispatcher, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([
                $this->operationResult(OperationStatus::Failed),
                $this->operationResult(OperationStatus::Failed),
                $this->operationResult(OperationStatus::Completed),
            ]));
    }

    #[Test]
    public function staysSilentBelowTheThreshold(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->method('isEnabled')->willReturn(true);
        $dispatcher->expects(self::never())->method('dispatch');

        // 1 of 3 failed = 0.33 < 0.5
        (new BulkFailureNotificationListener($dispatcher, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([
                $this->operationResult(OperationStatus::Failed),
                $this->operationResult(OperationStatus::Completed),
                $this->operationResult(OperationStatus::Completed),
            ]));
    }

    #[Test]
    public function staysSilentWhenTheDispatcherIsDisabled(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->method('isEnabled')->willReturn(false);
        $dispatcher->expects(self::never())->method('dispatch');

        (new BulkFailureNotificationListener($dispatcher, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([$this->operationResult(OperationStatus::Failed)]));
    }

    #[Test]
    public function staysSilentWhenThereAreNoResults(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->method('isEnabled')->willReturn(true);
        $dispatcher->expects(self::never())->method('dispatch');

        (new BulkFailureNotificationListener($dispatcher, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([]));
    }

    #[Test]
    public function objectFailuresNotifyEvenWhenThereAreNoAssetResults(): void
    {
        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->method('isEnabled')->willReturn(true);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (Notification $notification): bool =>
                $notification->kind === 'bulk.failure_rate'
                && $notification->severity === NotificationSeverity::Critical
                && $notification->title === 'Asset Pilot: high failure rate in a bulk run'
                && $notification->message === '2 of 2 objects failed (100%).'
                && $notification->context === ['failed' => 2, 'total' => 2, 'failureRate' => 1],
            ));

        (new BulkFailureNotificationListener($dispatcher, failureRateThreshold: 0.5))
            ->onBulkCompleted($this->event([], [
                new BulkObjectResult(1, BulkObjectStatus::Failed, 'Missing.'),
                new BulkObjectResult(2, BulkObjectStatus::Failed, 'Denied.'),
            ]));
    }
}
