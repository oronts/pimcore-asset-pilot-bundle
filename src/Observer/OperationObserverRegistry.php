<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Observer;

use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;

final class OperationObserverRegistry
{
    /** @var array<string, DurableOperationObserverInterface> */
    private array $observers = [];

    /** @param iterable<DurableOperationObserverInterface> $observers */
    public function __construct(iterable $observers)
    {
        foreach ($observers as $observer) {
            $id = trim($observer->id());
            if ($id === '') {
                throw new \LogicException('Durable operation observer IDs must not be empty.');
            }
            if (isset($this->observers[$id])) {
                throw new \LogicException(sprintf('Duplicate durable operation observer ID "%s".', $id));
            }
            $this->observers[$id] = $observer;
        }
    }

    public function get(string $id): ?DurableOperationObserverInterface
    {
        return $this->observers[$id] ?? null;
    }

    /** @return list<PreparedDelivery> */
    public function prepare(OperationIntent $intent): array
    {
        $prepared = [];
        $keys = [];
        foreach ($this->observers as $observerId => $observer) {
            foreach ($observer->prepare($intent) as $delivery) {
                if ($delivery->observerId !== $observerId) {
                    throw new \LogicException(sprintf(
                        'Observer "%s" prepared a delivery for observer "%s".',
                        $observerId,
                        $delivery->observerId,
                    ));
                }
                if (isset($keys[$delivery->deliveryKey])) {
                    throw new \LogicException(sprintf('Duplicate durable delivery key "%s".', $delivery->deliveryKey));
                }
                $keys[$delivery->deliveryKey] = true;
                $prepared[] = $delivery;
            }
        }

        return $prepared;
    }
}
