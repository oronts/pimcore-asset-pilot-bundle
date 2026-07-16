<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Symfony\Contracts\EventDispatcher\Event;

final class DurableOperationEvent extends Event
{
    public function __construct(public readonly DeliveryEnvelope $delivery) {}
}
