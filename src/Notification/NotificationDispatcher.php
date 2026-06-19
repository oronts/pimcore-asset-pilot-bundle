<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

use Psr\Log\LoggerInterface;

/**
 * Fans a notification out to every tagged notifier, isolating each: a transport that throws is
 * logged and never blocks the others or the operation that triggered the notification.
 */
class NotificationDispatcher
{
    /**
     * @param iterable<NotifierInterface> $notifiers
     */
    public function __construct(
        private readonly iterable $notifiers,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatch(string $title, string $message): void
    {
        foreach ($this->notifiers as $notifier) {
            try {
                $notifier->notify($title, $message);
            } catch (\Throwable $e) {
                $this->logger->warning('Asset Pilot: notifier {notifier} failed: {error}', [
                    'notifier' => $notifier::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
