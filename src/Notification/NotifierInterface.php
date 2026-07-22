<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

/**
 * A notification transport. Implement and tag `oronts_asset_pilot.notifier` to add email, Slack, or a
 * webhook. Every transport receives the complete typed Notification, including stable kind, severity,
 * presentation text, and safe scalar context; transports never need to parse prose.
 */
interface NotifierInterface
{
    public function notify(Notification $notification): void;
}
