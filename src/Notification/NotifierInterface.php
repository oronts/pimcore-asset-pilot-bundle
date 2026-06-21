<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

/**
 * A notification transport. Implement and tag `oronts_asset_pilot.notifier` to add email, Slack, a
 * webhook, etc.; the bundle ships a Pimcore in-app (backend "bell") notifier. All tagged notifiers
 * receive every dispatched notification.
 */
interface NotifierInterface
{
    public function notify(string $title, string $message): void;
}
