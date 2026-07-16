<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Message\OperationDeliveryMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class OperationDeliveryDispatcher implements OperationDeliveryDispatcherInterface
{
    public function __construct(
        private readonly OperationDeliveryStoreInterface $deliveries,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{dispatched: list<string>, failed: list<string>} */
    public function dispatchDue(int $limit = 100): array
    {
        $dispatched = [];
        $failed = [];
        foreach ($this->deliveries->due($limit) as $deliveryId) {
            try {
                $this->messageBus->dispatch(new OperationDeliveryMessage($deliveryId));
                $dispatched[] = $deliveryId;
            } catch (\Throwable $e) {
                $failed[] = $deliveryId;
                $this->logger->error('Asset Pilot: could not queue durable delivery {delivery}: {error}', [
                    'delivery' => $deliveryId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return ['dispatched' => $dispatched, 'failed' => $failed];
    }
}
