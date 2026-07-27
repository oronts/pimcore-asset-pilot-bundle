<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ObserverAuditReconciliationStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationDeliveryAudit;
use Oronts\AssetPilotBundle\Observer\DurableOperationObserverInterface;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

class OperationDeliveryProcessor implements OperationDeliveryProcessorInterface
{
    public function __construct(
        private readonly OperationDeliveryStoreInterface $deliveries,
        private readonly OperationObserverRegistry $observers,
        private readonly ActorContextStore $actors,
        private readonly ElementAuthorizationInterface $authorization,
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
        $audit = $this->deliveries->awaitingAudit($deliveryId);
        if ($audit !== null) {
            return $this->reconcileAuditSafely($audit);
        }

        $exhausted = $this->deliveries->deadLetterExhausted(
            $deliveryId,
            $this->maxAttempts,
            'The durable delivery lease expired after the maximum number of attempts.',
        );
        if ($exhausted !== null) {
            $this->logger->error('Asset Pilot: durable delivery {delivery} exhausted after an abandoned lease.', [
                'delivery' => $exhausted->deliveryId,
                'observer' => $exhausted->observerId,
                'operation' => $exhausted->operationId,
                'attempts' => $exhausted->attempts,
            ]);
            $audit = $this->deliveries->awaitingAudit($deliveryId);

            return $audit === null ? OperationDeliveryStatus::Dead : $this->reconcileAuditSafely($audit);
        }

        $delivery = $this->deliveries->claim($deliveryId, bin2hex(random_bytes(16)), $this->leaseSeconds);
        if ($delivery === null) {
            return null;
        }

        $delivery = $delivery->withLeaseHeartbeat(
            fn (): bool => $this->deliveries->renewLease($delivery, $this->leaseSeconds),
        );

        $observer = $this->observers->get($delivery->observerId);
        if ($observer === null) {
            $this->logger->error('Asset Pilot: durable observer {observer} is not registered for delivery {delivery}.', [
                'observer' => $delivery->observerId,
                'delivery' => $delivery->deliveryId,
            ]);

            return $this->deadLetter($delivery, 'The durable observer is not registered.');
        }

        try {
            $delivery->heartbeat();
            $this->deliver($delivery, $observer);
            $delivery->heartbeat();

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
        // While this delivery holds the asset lock, one heartbeat must renew the delivery lease AND the asset
        // lock together and fail closed if either is lost: otherwise an observer that heartbeats per the docs
        // keeps a live delivery lease while its asset lock silently expires and a concurrent mutation acquires
        // the asset. refreshAsset throws on a lost lock; renewLease===false throws via heartbeat().
        $fenced = $delivery->withLeaseHeartbeat(function () use ($delivery, $assetId): bool {
            $this->loopGuard->refreshAsset($assetId);

            return $this->deliveries->renewLease($delivery, $this->leaseSeconds);
        });
        try {
            $fenced->heartbeat();
            $observer->deliver($fenced);
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

        $audit = $this->deliveries->awaitingAudit($delivery->deliveryId);
        if ($audit === null) {
            return OperationDeliveryStatus::Delivered;
        }

        return $this->reconcileAuditSafely($audit);
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

        $audit = $this->deliveries->awaitingAudit($delivery->deliveryId);

        return $audit === null ? OperationDeliveryStatus::Dead : $this->reconcileAuditSafely($audit);
    }

    private function deferAudit(OperationDeliveryAudit $delivery): void
    {
        if (!$this->deliveries->deferAudit(
            $delivery,
            $this->now()->modify(sprintf('+%d seconds', $this->baseRetrySeconds)),
        ) && $this->deliveries->awaitingAudit($delivery->deliveryId) !== null) {
            $this->logger->warning('Asset Pilot: durable delivery {delivery} audit retry could not be deferred.', [
                'delivery' => $delivery->deliveryId,
                'operation' => $delivery->operationId,
            ]);
        }
    }

    private function reconcileAuditSafely(OperationDeliveryAudit $delivery): OperationDeliveryStatus
    {
        try {
            return $this->reconcileAudit($delivery);
        } catch (\Throwable $e) {
            $this->deferAudit($delivery);
            $this->logger->error('Asset Pilot: operation {operation} observer status could not be reconciled after delivery {delivery}.', [
                'operation' => $delivery->operationId,
                'delivery' => $delivery->deliveryId,
                'status' => $delivery->status->value,
                'exception' => $e,
            ]);

            return $delivery->status;
        }
    }

    private function reconcileAudit(OperationDeliveryAudit $delivery): OperationDeliveryStatus
    {
        $status = match ($delivery->status) {
            OperationDeliveryStatus::Dead => $this->journal->recordObserverFailure($delivery->operationId, $delivery->observerId),
            OperationDeliveryStatus::Delivered => $this->journal->resolveObserverFailures($delivery->operationId),
            default => throw new \LogicException('Only terminal deliveries can reconcile their audit state.'),
        };
        if ($status === ObserverAuditReconciliationStatus::Deferred) {
            $this->deferAudit($delivery);
            $this->logger->warning('Asset Pilot: durable delivery {delivery} is waiting for audit reconciliation.', [
                'delivery' => $delivery->deliveryId,
                'operation' => $delivery->operationId,
                'observer' => $delivery->observerId,
            ]);

            return $delivery->status;
        }

        if (!$this->deliveries->markAuditReconciled($delivery)
            && $this->deliveries->awaitingAudit($delivery->deliveryId) !== null
        ) {
            $this->logger->warning('Asset Pilot: durable delivery {delivery} audit marker changed during reconciliation.', [
                'delivery' => $delivery->deliveryId,
                'operation' => $delivery->operationId,
            ]);
        }

        return $delivery->status;
    }
}
