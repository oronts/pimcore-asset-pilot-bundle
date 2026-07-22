<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

use Psr\Log\LoggerInterface;

/**
 * Fans a notification out to every tagged notifier, isolating each: a transport that throws is
 * logged and never blocks the others or the operation that triggered the notification.
 *
 * The opt-in gate lives here (not in each caller) so every notification path shares one switch.
 * Callers on a hot path can short-circuit cheaply via isEnabled() before assembling a message.
 */
class NotificationDispatcher implements NotificationDispatcherInterface
{
    /**
     * @param iterable<NotifierInterface> $notifiers
     */
    public function __construct(
        private readonly iterable $notifiers,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = false,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function dispatch(Notification $notification): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            foreach ($this->notifiers as $notifier) {
                try {
                    $notifier->notify($notification);
                } catch (\Throwable $e) {
                    $this->logger->warning('Asset Pilot: notifier {notifier} failed: {error}', [
                        'notifier' => $notifier::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Resolving the notifier iterator (lazy service construction) must never propagate into the
            // operation that triggered the notification: notifications are best-effort, end to end.
            // Logged at error with the exception so a misconfigured notifier stays observable, not silent.
            $this->logger->error('Asset Pilot: notification dispatch aborted: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
