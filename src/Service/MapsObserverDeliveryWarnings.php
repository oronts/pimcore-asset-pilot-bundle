<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;

/**
 * Dispatches a completed-mutation observer event non-fatally and maps any delivery failure to a single
 * operator-facing warning, so a mutation service reports observer problems without failing the committed
 * change. The using service must expose `$this->eventDispatcher` and `$this->logger`.
 */
trait MapsObserverDeliveryWarnings
{
    /**
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    private function observerWarnings(AssetMutationEvent $event, string $eventName, string $warning, array $context): array
    {
        return NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            $event,
            $eventName,
            $this->logger,
            $context,
        ) === [] ? [] : [$warning];
    }
}
