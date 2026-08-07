<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationDeliveryAudit;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Support\BulkIds;

class OperationDeliveryStore implements OperationDeliveryStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function prepare(OperationHandle $operation, array $deliveries): array
    {
        if ($deliveries === []) {
            return [];
        }

        $ids = [];
        $keys = [];
        foreach ($deliveries as $delivery) {
            if (isset($keys[$delivery->observerId][$delivery->deliveryKey])) {
                throw new \InvalidArgumentException(sprintf('Duplicate prepared delivery key "%s".', $delivery->deliveryKey));
            }
            $keys[$delivery->observerId][$delivery->deliveryKey] = true;
            $ids[] = $this->deliveryId($operation->operationId, $delivery->observerId, $delivery->deliveryKey);
        }

        $now = $this->format($this->now());
        $intent = $this->encode($operation->intent->toArray());
        $this->connection->transactional(function () use ($operation, $deliveries, $ids, $intent, $now): void {
            foreach ($deliveries as $index => $delivery) {
                $row = [
                    'id' => $ids[$index],
                    'operation_id' => $operation->operationId,
                    'delivery_key' => $delivery->deliveryKey,
                    'observer_id' => $delivery->observerId,
                    'outcome' => $delivery->outcome->value,
                    'intent_payload' => $intent,
                    'payload' => $this->encode($delivery->payload),
                    'status' => OperationDeliveryStatus::Prepared->value,
                    'attempts' => 0,
                    'available_at' => $now,
                    'lock_token' => null,
                    'locked_until' => null,
                    'last_error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'delivered_at' => null,
                    'audit_reconciled_at' => null,
                ];

                $existing = $this->connection->fetchAssociative(
                    'SELECT operation_id, delivery_key, observer_id, outcome, intent_payload, payload FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ?',
                    [$ids[$index]],
                );
                if ($existing === false) {
                    $this->connection->insert(Installer::TABLE_OPERATION_DELIVERY, $row);
                    continue;
                }
                if (!$this->samePreparedDelivery($existing, $row)) {
                    throw new \LogicException(sprintf('Stable delivery ID "%s" is already used by another payload.', $ids[$index]));
                }
            }
        });

        return $ids;
    }

    public function activateForOutcome(int $operationId, OperationDeliveryOutcome $outcome): int
    {
        if ($operationId <= 0) {
            throw new \InvalidArgumentException('An operation ID must be positive.');
        }

        return $this->connection->transactional(function () use ($operationId, $outcome): int {
            $conflicts = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE operation_id = ? AND outcome <> ? AND status NOT IN (?, ?)',
                [$operationId, $outcome->value, OperationDeliveryStatus::Prepared->value, OperationDeliveryStatus::Cancelled->value],
            );
            if ($conflicts > 0) {
                throw new \LogicException(sprintf('Operation %d already activated the opposite delivery outcome.', $operationId));
            }

            $now = $this->format($this->now());
            $activated = $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, available_at = ?, updated_at = ? WHERE operation_id = ? AND outcome = ? AND status = ?',
                [OperationDeliveryStatus::Pending->value, $now, $now, $operationId, $outcome->value, OperationDeliveryStatus::Prepared->value],
            );
            $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, updated_at = ? WHERE operation_id = ? AND outcome <> ? AND status = ?',
                [OperationDeliveryStatus::Cancelled->value, $now, $operationId, $outcome->value, OperationDeliveryStatus::Prepared->value],
            );

            return $activated;
        });
    }

    public function due(int $limit = 100): array
    {
        $this->assertLimit($limit);

        $rows = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(Installer::TABLE_OPERATION_DELIVERY)
            ->where('((status IN (:pending, :retry) AND available_at <= :now) OR (status = :processing AND locked_until <= :now) OR (status IN (:terminal) AND audit_reconciled_at IS NULL AND available_at <= :now))')
            ->setParameter('pending', OperationDeliveryStatus::Pending->value)
            ->setParameter('retry', OperationDeliveryStatus::Retry->value)
            ->setParameter('processing', OperationDeliveryStatus::Processing->value)
            ->setParameter('terminal', [OperationDeliveryStatus::Dead->value, OperationDeliveryStatus::Delivered->value], \Doctrine\DBAL\ArrayParameterType::STRING)
            ->setParameter('now', $this->format($this->now()))
            ->orderBy('available_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $rows);
    }

    public function claim(string $deliveryId, string $token, int $leaseSeconds): ?DeliveryEnvelope
    {
        if ($deliveryId === '' || $token === '' || strlen($token) > 64) {
            throw new \InvalidArgumentException('A claim requires a delivery ID and a token of at most 64 bytes.');
        }
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('A delivery claim lease must be positive.');
        }

        $now = $this->now();
        $formattedNow = $this->format($now);
        $updated = $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, attempts = attempts + 1, lock_token = ?, locked_until = ?, updated_at = ? WHERE id = ? AND ((status IN (?, ?) AND available_at <= ?) OR (status = ? AND locked_until <= ?))',
            [
                OperationDeliveryStatus::Processing->value,
                $token,
                $this->format($now->modify(sprintf('+%d seconds', $leaseSeconds))),
                $formattedNow,
                $deliveryId,
                OperationDeliveryStatus::Pending->value,
                OperationDeliveryStatus::Retry->value,
                $formattedNow,
                OperationDeliveryStatus::Processing->value,
                $formattedNow,
            ],
        );
        if ($updated !== 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ? AND status = ? AND lock_token = ?',
            [$deliveryId, OperationDeliveryStatus::Processing->value, $token],
        );
        if ($row === false) {
            return null;
        }

        return $this->envelope($row);
    }

    public function renewLease(DeliveryEnvelope $delivery, int $leaseSeconds): bool
    {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('A delivery lease renewal must be positive.');
        }

        $now = $this->now();
        $formattedNow = $this->format($now);
        $parameters = [
            $this->format($now->modify(sprintf('+%d seconds', $leaseSeconds))),
            $formattedNow,
            $delivery->deliveryId,
            OperationDeliveryStatus::Processing->value,
            $delivery->claimToken,
            $formattedNow,
        ];
        $updated = $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET locked_until = ?, updated_at = ? WHERE id = ? AND status = ? AND lock_token = ? AND locked_until > ?',
            $parameters,
        );
        if ($updated === 1) {
            return true;
        }

        // MariaDB reports zero changed rows when repeated heartbeats land in the same whole second.
        $ownershipNow = $this->format($this->now());
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ? AND status = ? AND lock_token = ? AND locked_until > ?',
            [$delivery->deliveryId, OperationDeliveryStatus::Processing->value, $delivery->claimToken, $ownershipNow],
        ) === 1;
    }

    public function deadLetterExhausted(string $deliveryId, int $maxAttempts, string $error): ?DeadOperationDelivery
    {
        if ($deliveryId === '' || $maxAttempts <= 0 || trim($error) === '') {
            throw new \InvalidArgumentException('An exhausted delivery requires an ID, attempt limit, and error.');
        }

        $now = $this->format($this->now());
        $updated = $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, lock_token = NULL, locked_until = NULL, last_error = ?, audit_reconciled_at = NULL, updated_at = ? WHERE id = ? AND attempts >= ? AND ((status IN (?, ?) AND available_at <= ?) OR (status = ? AND locked_until <= ?))',
            [
                OperationDeliveryStatus::Dead->value,
                $error,
                $now,
                $deliveryId,
                $maxAttempts,
                OperationDeliveryStatus::Pending->value,
                OperationDeliveryStatus::Retry->value,
                $now,
                OperationDeliveryStatus::Processing->value,
                $now,
            ],
        );
        if ($updated !== 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ? AND status = ?',
            [$deliveryId, OperationDeliveryStatus::Dead->value],
        );

        return $row === false ? null : $this->deadDelivery($row);
    }

    public function markDelivered(DeliveryEnvelope $delivery): bool
    {
        $now = $this->format($this->now());

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, lock_token = NULL, locked_until = NULL, last_error = NULL, audit_reconciled_at = NULL, updated_at = ?, delivered_at = ? WHERE id = ? AND status = ? AND lock_token = ?',
            [OperationDeliveryStatus::Delivered->value, $now, $now, $delivery->deliveryId, OperationDeliveryStatus::Processing->value, $delivery->claimToken],
        ) === 1;
    }

    public function markRetry(DeliveryEnvelope $delivery, string $error, \DateTimeImmutable $availableAt): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, available_at = ?, lock_token = NULL, locked_until = NULL, last_error = ?, updated_at = ? WHERE id = ? AND status = ? AND lock_token = ?',
            [
                OperationDeliveryStatus::Retry->value,
                $this->format($availableAt),
                $error,
                $this->format($this->now()),
                $delivery->deliveryId,
                OperationDeliveryStatus::Processing->value,
                $delivery->claimToken,
            ],
        ) === 1;
    }

    public function markDead(DeliveryEnvelope $delivery, string $error): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, lock_token = NULL, locked_until = NULL, last_error = ?, audit_reconciled_at = NULL, updated_at = ? WHERE id = ? AND status = ? AND lock_token = ?',
            [
                OperationDeliveryStatus::Dead->value,
                $error,
                $this->format($this->now()),
                $delivery->deliveryId,
                OperationDeliveryStatus::Processing->value,
                $delivery->claimToken,
            ],
        ) === 1;
    }

    public function awaitingAudit(string $deliveryId): ?OperationDeliveryAudit
    {
        if ($deliveryId === '') {
            throw new \InvalidArgumentException('A delivery audit reconciliation requires an ID.');
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ? AND status IN (?, ?) AND audit_reconciled_at IS NULL',
            [$deliveryId, OperationDeliveryStatus::Dead->value, OperationDeliveryStatus::Delivered->value],
        );

        return $row === false ? null : $this->audit($row);
    }

    public function markAuditReconciled(OperationDeliveryAudit $delivery): bool
    {
        $now = $this->format($this->now());

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET audit_reconciled_at = ?, updated_at = ? WHERE id = ? AND status = ? AND audit_reconciled_at IS NULL AND attempts = ? AND updated_at = ?',
            [
                $now,
                $now,
                $delivery->deliveryId,
                $delivery->status->value,
                $delivery->attempts,
                $delivery->updatedAt,
            ],
        ) === 1;
    }

    public function deferAudit(OperationDeliveryAudit $delivery, \DateTimeImmutable $availableAt): bool
    {
        $now = $this->format($this->now());

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET available_at = ?, updated_at = ? WHERE id = ? AND status = ? AND audit_reconciled_at IS NULL AND attempts = ? AND updated_at = ?',
            [
                $this->format($availableAt),
                $now,
                $delivery->deliveryId,
                $delivery->status->value,
                $delivery->attempts,
                $delivery->updatedAt,
            ],
        ) === 1;
    }

    public function dead(int $limit = 100): array
    {
        $this->assertLimit($limit);

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(Installer::TABLE_OPERATION_DELIVERY)
            ->where('status = :status AND audit_reconciled_at IS NOT NULL')
            ->setParameter('status', OperationDeliveryStatus::Dead->value)
            ->orderBy('updated_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->deadDelivery(...), $rows);
    }

    public function requeueDead(array $deliveries): int
    {
        if ($deliveries === [] || count($deliveries) > 1_000) {
            throw new \InvalidArgumentException('A dead delivery retry requires between one and 1,000 reviewed deliveries.');
        }

        $reviewed = [];
        foreach ($deliveries as $delivery) {
            if (!$delivery instanceof DeadOperationDelivery) {
                throw new \InvalidArgumentException('Dead delivery retries must contain reviewed dead deliveries.');
            }
            if (isset($reviewed[$delivery->deliveryId])) {
                throw new \InvalidArgumentException(sprintf('Duplicate dead delivery ID "%s".', $delivery->deliveryId));
            }
            $reviewed[$delivery->deliveryId] = $delivery;
        }

        return $this->connection->transactional(function () use ($reviewed): int {
            $current = [];
            foreach ($reviewed as $deliveryId => $delivery) {
                $row = $this->connection->fetchAssociative(
                    'SELECT * FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ?',
                    [$deliveryId],
                );
                if ($row === false
                    || (string) $row['status'] !== OperationDeliveryStatus::Dead->value
                    || $row['audit_reconciled_at'] === null
                    || !hash_equals($delivery->fingerprint, $this->fingerprint($row))
                ) {
                    throw new \LogicException(sprintf('Dead delivery "%s" changed after it was reviewed.', $deliveryId));
                }
                $current[$deliveryId] = $row;
            }

            $now = $this->format($this->now());
            foreach ($current as $deliveryId => $row) {
                $updated = $this->connection->executeStatement(
                    'UPDATE ' . Installer::TABLE_OPERATION_DELIVERY . ' SET status = ?, attempts = 0, available_at = ?, lock_token = NULL, locked_until = NULL, last_error = NULL, audit_reconciled_at = NULL, updated_at = ?, delivered_at = NULL WHERE id = ? AND status = ? AND audit_reconciled_at IS NOT NULL AND attempts = ? AND updated_at = ?',
                    [
                        OperationDeliveryStatus::Pending->value,
                        $now,
                        $now,
                        $deliveryId,
                        OperationDeliveryStatus::Dead->value,
                        (int) $row['attempts'],
                        (string) $row['updated_at'],
                    ],
                );
                if ($updated !== 1) {
                    throw new \LogicException(sprintf('Dead delivery "%s" changed while it was being requeued.', $deliveryId));
                }
            }

            return count($current);
        });
    }

    public function hasDead(int $operationId): bool
    {
        if ($operationId <= 0) {
            return false;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE operation_id = ? AND status = ?',
            [$operationId, OperationDeliveryStatus::Dead->value],
        ) > 0;
    }

    public function hasUnresolved(int $operationId): bool
    {
        if ($operationId <= 0) {
            return false;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE operation_id = ? AND status IN (?, ?, ?, ?)',
            [
                $operationId,
                OperationDeliveryStatus::Prepared->value,
                OperationDeliveryStatus::Pending->value,
                OperationDeliveryStatus::Processing->value,
                OperationDeliveryStatus::Retry->value,
            ],
        ) > 0;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    private function envelope(array $row): DeliveryEnvelope
    {
        return new DeliveryEnvelope(
            deliveryId: (string) $row['id'],
            operationId: (int) $row['operation_id'],
            deliveryKey: (string) $row['delivery_key'],
            observerId: (string) $row['observer_id'],
            outcome: OperationDeliveryOutcome::from((string) $row['outcome']),
            intent: OperationIntent::fromArray($this->decode((string) $row['intent_payload'])),
            payload: $this->decode((string) $row['payload']),
            attempt: (int) $row['attempts'],
            claimToken: (string) $row['lock_token'],
        );
    }

    /** @param array<string, mixed> $row */
    private function audit(array $row): OperationDeliveryAudit
    {
        return new OperationDeliveryAudit(
            deliveryId: (string) $row['id'],
            operationId: (int) $row['operation_id'],
            observerId: (string) $row['observer_id'],
            status: OperationDeliveryStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function deadDelivery(array $row): DeadOperationDelivery
    {
        return new DeadOperationDelivery(
            deliveryId: (string) $row['id'],
            operationId: (int) $row['operation_id'],
            deliveryKey: (string) $row['delivery_key'],
            observerId: (string) $row['observer_id'],
            outcome: OperationDeliveryOutcome::from((string) $row['outcome']),
            attempts: (int) $row['attempts'],
            lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
            updatedAt: (string) $row['updated_at'],
            fingerprint: $this->fingerprint($row),
        );
    }

    /** @param array<string, mixed> $row */
    private function fingerprint(array $row): string
    {
        $snapshot = [];
        foreach ([
            'id',
            'operation_id',
            'delivery_key',
            'observer_id',
            'outcome',
            'intent_payload',
            'payload',
            'status',
            'attempts',
            'available_at',
            'lock_token',
            'locked_until',
            'last_error',
            'created_at',
            'updated_at',
            'delivered_at',
            'audit_reconciled_at',
        ] as $column) {
            $snapshot[$column] = $row[$column] === null ? null : (string) $row[$column];
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function deliveryId(int $operationId, string $observerId, string $deliveryKey): string
    {
        return hash('sha256', $operationId . "\0" . $observerId . "\0" . $deliveryKey);
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > BulkIds::MAX) {
            throw new \InvalidArgumentException(sprintf('Delivery query limit must be between 1 and %d.', BulkIds::MAX));
        }
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $prepared
     */
    private function samePreparedDelivery(array $existing, array $prepared): bool
    {
        foreach (['operation_id', 'delivery_key', 'observer_id', 'outcome', 'intent_payload', 'payload'] as $column) {
            if ((string) $existing[$column] !== (string) $prepared[$column]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function decode(string $payload): array
    {
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('A durable delivery payload must decode to an object.');
        }

        return $decoded;
    }

    private function format(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
