<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationDeliveryAudit;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;

interface OperationDeliveryStoreInterface
{
    /**
     * @param list<PreparedDelivery> $deliveries
     * @return list<string> stable delivery IDs
     */
    public function prepare(OperationHandle $operation, array $deliveries): array;

    public function activateForOutcome(int $operationId, OperationDeliveryOutcome $outcome): int;

    /** @return list<string> */
    public function due(int $limit = 100): array;

    public function claim(string $deliveryId, string $token, int $leaseSeconds): ?DeliveryEnvelope;

    public function renewLease(DeliveryEnvelope $delivery, int $leaseSeconds): bool;

    public function deadLetterExhausted(string $deliveryId, int $maxAttempts, string $error): ?DeadOperationDelivery;

    public function markDelivered(DeliveryEnvelope $delivery): bool;

    public function markRetry(DeliveryEnvelope $delivery, string $error, \DateTimeImmutable $availableAt): bool;

    public function markDead(DeliveryEnvelope $delivery, string $error): bool;

    public function awaitingAudit(string $deliveryId): ?OperationDeliveryAudit;

    public function markAuditReconciled(OperationDeliveryAudit $delivery): bool;

    public function deferAudit(OperationDeliveryAudit $delivery, \DateTimeImmutable $availableAt): bool;

    /** @return list<DeadOperationDelivery> */
    public function dead(int $limit = 100): array;

    /** @param list<DeadOperationDelivery> $deliveries */
    public function requeueDead(array $deliveries): int;

    public function hasDead(int $operationId): bool;

    public function hasUnresolved(int $operationId): bool;
}
