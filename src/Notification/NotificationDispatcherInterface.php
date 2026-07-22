<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

/**
 * Fan-out policy for operational notifications.
 * Implementations respect isEnabled() and must not let transport failures escape dispatch().
 */
interface NotificationDispatcherInterface
{
    public function isEnabled(): bool;

    public function dispatch(Notification $notification): void;
}
