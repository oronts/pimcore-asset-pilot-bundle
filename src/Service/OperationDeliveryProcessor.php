<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Observer\DurableOperationObserverInterface;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

class OperationDeliveryProcessor
{
    public function __construct(
        private readonly OperationDeliveryStoreInterface $deliveries,
        private readonly OperationObserverRegistry $observers,
        private readonly ActorContextStore $actors,
        private readonly ElementAuthorization $authorization,
        private readonly LoopGuard $loopGuard,
        private readonly OperationJournalInterface $journal,
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts = 5,
        private readonly int $baseRetrySeconds = 30,
        private readonly int $maxRetrySeconds = 3_600,
        private readonly int $leaseSeconds = 300,
    ) {
        if ($maxAttempts <= 0 || $baseRetrySeconds <= 0 || $maxRetrySeconds < $baseRetrySeconds || $leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Durable delivery retry and lease settings must be positive and coherent.');
        }
    }

    public function process(string $deliveryId): ?OperationDeliveryStatus
    {
        $delivery = $this->deliveries->claim($deliveryId, bin2hex(random_bytes(16)), $this->leaseSeconds);
        if ($delivery === null) {
            return null;
        }

        $observer = $this->observers->get($delivery->observerId);
        if ($observer === null) {
            $this->logger->error('Asset Pilot: durable observer {observer} is not registered for delivery {delivery}.', [
                'observer' => $delivery->observerId,
                'delivery' => $delivery->deliveryId,
            ]);

            return $this->deadLetter($delivery, 'The durable observer is not registered.');
        }

        try {
            $this->deliver($delivery, $observer);

            return $this->completeDelivery($delivery);
        } catch (\Throwable $e) {
            return $this->handleFailure($delivery, $e);
        }
    }

    /** @return array<string, OperationDeliveryStatus|null> */
    public function processDue(int $limit = 100): array
    {
        $results = [];
        foreach ($this->deliveries->due($limit) as $deliveryId) {
            $results[$deliveryId] = $this->process($deliveryId);
        }

        return $results;
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function deliver(DeliveryEnvelope $delivery, DurableOperationObserverInterface $observer): void
    {
        $this->actors->runAs($delivery->intent->actor, function () use ($delivery, $observer): void {
            $permission = $observer->requiredAssetPermission();
            if ($permission === null) {
                $observer->deliver($delivery);

                return;
            }
            if (trim($permission) === '') {
                throw new \LogicException('A durable observer asset permission cannot be empty.');
            }

            $this->deliverForAsset($delivery, $observer, $permission);
        });
    }

    private function deliverForAsset(
        DeliveryEnvelope $delivery,
        DurableOperationObserverInterface $observer,
        string $permission,
    ): void {
        $assetId = $delivery->intent->assetId;
        $asset = $this->loadAsset($assetId);
        if ($asset === null || $asset instanceof Asset\Folder) {
            throw new \RuntimeException('The operation asset is unavailable for observer delivery.');
        }
        if (!$this->authorization->isAllowed($asset, $permission, $delivery->intent->actor)) {
            throw new \RuntimeException(sprintf('The initiating actor is no longer permitted to %s the operation asset.', $permission));
        }
        if (!$this->loopGuard->acquireAsset($assetId)) {
            throw new \RuntimeException('The operation asset is busy.');
        }

        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $this->loopGuard->refreshAsset($assetId);
            $observer->deliver($delivery);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    private function handleFailure(DeliveryEnvelope $delivery, \Throwable $error): OperationDeliveryStatus
    {
        $this->logger->error('Asset Pilot: durable delivery {delivery} attempt {attempt} failed: {error}', [
            'delivery' => $delivery->deliveryId,
            'observer' => $delivery->observerId,
            'operation' => $delivery->operationId,
            'attempt' => $delivery->attempt,
            'error' => $error->getMessage(),
            'exception' => $error,
        ]);

        if ($delivery->attempt >= $this->maxAttempts) {
            return $this->deadLetter($delivery, $error->getMessage());
        }

        $availableAt = $this->now()->modify(sprintf('+%d seconds', $this->retryDelay($delivery->attempt)));

        return $this->deliveries->markRetry($delivery, $error->getMessage(), $availableAt)
            ? OperationDeliveryStatus::Retry
            : OperationDeliveryStatus::Processing;
    }

    private function completeDelivery(DeliveryEnvelope $delivery): OperationDeliveryStatus
    {
        if (!$this->deliveries->markDelivered($delivery)) {
            return OperationDeliveryStatus::Processing;
        }

        try {
            $this->journal->resolveObserverFailures($delivery->operationId);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: operation {operation} observer status could not be reconciled after delivery {delivery}.', [
                'operation' => $delivery->operationId,
                'delivery' => $delivery->deliveryId,
                'exception' => $e,
            ]);
        }

        return OperationDeliveryStatus::Delivered;
    }

    private function retryDelay(int $attempt): int
    {
        $delay = $this->baseRetrySeconds;
        for ($current = 1; $current < $attempt && $delay < $this->maxRetrySeconds; ++$current) {
            $delay = min($this->maxRetrySeconds, $delay * 2);
        }

        return $delay;
    }

    private function deadLetter(DeliveryEnvelope $delivery, string $error): OperationDeliveryStatus
    {
        if (!$this->deliveries->markDead($delivery, $error)) {
            return OperationDeliveryStatus::Processing;
        }

        $this->journal->recordObserverFailure(
            $delivery->operationId,
            sprintf('Durable observer "%s" did not complete.', $delivery->observerId),
        );

        return OperationDeliveryStatus::Dead;
    }
}
