<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Observer;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\DurableOperationEvent;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class DurableEventObserver implements DurableOperationObserverInterface
{
    public function __construct(private readonly EventDispatcherInterface $events) {}

    public function id(): string
    {
        return 'operation_events';
    }

    public function requiredAssetPermission(): ?string
    {
        return null;
    }

    public function prepare(OperationIntent $intent): iterable
    {
        return [
            new PreparedDelivery($this->id(), 'operation-event:success', OperationDeliveryOutcome::Success),
            new PreparedDelivery($this->id(), 'operation-event:failure', OperationDeliveryOutcome::Failure),
        ];
    }

    public function deliver(DeliveryEnvelope $delivery): void
    {
        $eventName = $delivery->outcome === OperationDeliveryOutcome::Success
            ? AssetPilotEvents::DURABLE_OPERATION_SUCCEEDED
            : AssetPilotEvents::DURABLE_OPERATION_FAILED;
        $this->events->dispatch(new DurableOperationEvent($delivery), $eventName);
    }
}
