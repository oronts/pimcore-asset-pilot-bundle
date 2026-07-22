<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

use Oronts\AssetPilotBundle\Enum\NotificationSeverity;

/**
 * Immutable routing and presentation data shared by every notification transport.
 * Context is intentionally flat and must contain operational metadata only, never secrets.
 */
readonly class Notification
{
    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    public function __construct(
        public string $kind,
        public NotificationSeverity $severity,
        public string $title,
        public string $message,
        public array $context = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $kind) !== 1) {
            throw new \InvalidArgumentException('Notification kind must be a stable lowercase identifier.');
        }
    }
}
