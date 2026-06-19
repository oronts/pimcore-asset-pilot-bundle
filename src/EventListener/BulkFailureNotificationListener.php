<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcher;

/**
 * Notifies (via the dispatcher) when a completed bulk run's failure rate crosses the configured
 * threshold, so a degraded run is surfaced rather than only logged. Opt-in (disabled by default).
 */
class BulkFailureNotificationListener
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly float $failureRateThreshold = 0.5,
    ) {}

    public function onBulkCompleted(BulkOrganizeEvent $event): void
    {
        if (!$this->dispatcher->isEnabled()) {
            return;
        }

        $total = count($event->results);
        if ($total === 0) {
            return;
        }

        $failed = count(array_filter(
            $event->results,
            static fn (OperationResult $result): bool => $result->status === OperationStatus::Failed,
        ));
        $rate = $failed / $total;
        if ($rate < $this->failureRateThreshold) {
            return;
        }

        $this->dispatcher->dispatch(
            'Asset Pilot: high failure rate in a bulk run',
            sprintf(
                '%d of %d operations failed (%d%%) across %d object(s).',
                $failed,
                $total,
                (int) round($rate * 100),
                count($event->objectIds),
            ),
        );
    }
}
