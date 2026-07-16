<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Service\OperationDeliveryDispatcherInterface;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

final class OperationDeliveryTask implements TaskInterface
{
    public function __construct(
        private readonly OperationDeliveryDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly int $defaultBatchSize,
    ) {}

    public function execute(): void
    {
        try {
            $result = $this->dispatcher->dispatchDue($this->defaultBatchSize);
            if ($result['dispatched'] !== [] || $result['failed'] !== []) {
                $this->logger->info('Asset Pilot: queued {dispatched} durable deliveries; {failed} could not be queued.', [
                    'dispatched' => count($result['dispatched']),
                    'failed' => count($result['failed']),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: durable delivery polling failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
