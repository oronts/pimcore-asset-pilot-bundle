<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\NotificationSeverity;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Notification\Notification;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcherInterface;

/**
 * Notifies (via the dispatcher) when a completed bulk run's failure rate crosses the configured
 * threshold, so a degraded run is surfaced rather than only logged. Opt-in (disabled by default).
 */
class BulkFailureNotificationListener
{
    public function __construct(
        private readonly NotificationDispatcherInterface $dispatcher,
        private readonly float $failureRateThreshold = 0.5,
    ) {}

    public function onBulkCompleted(BulkOrganizeEvent $event): void
    {
        if (!$this->dispatcher->isEnabled()) {
            return;
        }

        $total = count($event->objectResults);
        $failed = count(array_filter(
            $event->objectResults,
            static fn (BulkObjectResult $result): bool => $result->status === BulkObjectStatus::Failed,
        ));

        if ($total === 0) {
            $total = count($event->results);
            $failed = count(array_filter(
                $event->results,
                static fn (OperationResult $result): bool => $result->status === OperationStatus::Failed,
            ));
        }

        if ($total === 0) {
            return;
        }
        $rate = $failed / $total;
        if ($rate < $this->failureRateThreshold) {
            return;
        }

        $this->dispatcher->dispatch(new Notification(
            kind: 'bulk.failure_rate',
            severity: NotificationSeverity::Critical,
            title: 'Asset Pilot: high failure rate in a bulk run',
            message: sprintf(
                '%d of %d objects failed (%d%%).',
                $failed,
                $total,
                (int) round($rate * 100),
            ),
            context: [
                'failed' => $failed,
                'total' => $total,
                'failureRate' => $rate,
            ],
        ));
    }
}
