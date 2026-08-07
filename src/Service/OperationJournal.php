<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Enum\ObserverAuditReconciliationStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;

class OperationJournal implements OperationJournalInterface
{
    private const array COMPLETABLE_STATUSES = [
        OperationStatus::Completed,
        OperationStatus::CompletedWithObserverError,
        OperationStatus::Failed,
        OperationStatus::RecoveryRequired,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly OperationObserverRegistry $observers,
        private readonly OperationDeliveryStoreInterface $deliveries,
    ) {}

    public function begin(OperationIntent $intent): OperationHandle
    {
        $prepared = $this->observers->prepare($intent);

        return $this->connection->transactional(function () use ($intent, $prepared): OperationHandle {
            $createdAt = $this->format($intent->createdAt);
            $this->connection->insert(Installer::TABLE_AUDIT_LOG, [
                ...$this->operationRow($intent, OperationStatus::InProgress),
                'operation_kind' => $intent->kind->value,
                'actor_type' => $intent->actor->type->value,
                'parent_audit_id' => $intent->parentOperationId,
                'intent_payload' => $this->encode($intent->toArray()),
                'schema_version' => $intent->schemaVersion,
                'updated_at' => $createdAt,
                'committed_at' => null,
            ]);
            $operationId = (int) $this->connection->lastInsertId();
            if ($operationId <= 0) {
                throw new \RuntimeException('The operation journal did not return an identifier.');
            }

            $operation = new OperationHandle($operationId, $intent);
            $this->deliveries->prepare($operation, $prepared);

            return $operation;
        });
    }

    public function complete(
        OperationHandle $operation,
        OperationStatus $status,
        ?string $errorMessage = null,
        ?int $durationMs = null,
    ): bool {
        if (!in_array($status, self::COMPLETABLE_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Operation status "%s" cannot complete a journal entry.', $status->value));
        }

        return $this->connection->transactional(function () use ($operation, $status, $errorMessage, $durationMs): bool {
            $current = $this->connection->fetchOne(
                'SELECT status FROM ' . Installer::TABLE_AUDIT_LOG . ' WHERE id = ?',
                [$operation->operationId],
            );
            if ($current === false) {
                throw new \RuntimeException(sprintf('Operation journal entry %d does not exist.', $operation->operationId));
            }

            $currentStatus = OperationStatus::from((string) $current);
            if ($currentStatus !== $status
                && !in_array($currentStatus, [OperationStatus::InProgress, OperationStatus::RecoveryRequired], true)
            ) {
                throw new \LogicException(sprintf(
                    'Operation %d is already completed as "%s".',
                    $operation->operationId,
                    $currentStatus->value,
                ));
            }

            $now = $this->format($this->now());
            // Persist on a real transition, and also refresh the reason + updated_at on a same-status
            // recovery_required re-classification, so a repeated recovery diagnosis is written and the
            // row is not left immediately eligible for the next recovery scan.
            $mustPersist = $currentStatus !== $status || $status === OperationStatus::RecoveryRequired;
            if ($mustPersist && !$this->compareAndSetStatus(
                $operation,
                $currentStatus,
                $status,
                $errorMessage,
                $durationMs,
                $now,
            )) {
                $winner = $this->connection->fetchOne(
                    'SELECT status FROM ' . Installer::TABLE_AUDIT_LOG . ' WHERE id = ?',
                    [$operation->operationId],
                );
                if ($winner === false || OperationStatus::from((string) $winner) !== $status) {
                    throw new \LogicException(sprintf(
                        'Operation %d was completed concurrently with another outcome.',
                        $operation->operationId,
                    ));
                }
            }

            $outcome = $this->deliveryOutcome($status);
            if ($outcome !== null) {
                $this->deliveries->activateForOutcome($operation->operationId, $outcome);
            }

            return true;
        });
    }

    private function compareAndSetStatus(
        OperationHandle $operation,
        OperationStatus $currentStatus,
        OperationStatus $status,
        ?string $errorMessage,
        ?int $durationMs,
        string $now,
    ): bool {
        $row = [
            ...$this->operationRow($operation->intent, $status, $errorMessage, $durationMs),
            'updated_at' => $now,
            'committed_at' => $this->isSuccess($status) ? $now : null,
        ];
        $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($row)));

        return $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_AUDIT_LOG . ' SET ' . $assignments . ' WHERE id = ? AND status = ?',
            [...array_values($row), $operation->operationId, $currentStatus->value],
        ) === 1;
    }

    public function recoverable(int $limit = 100, int $staleSeconds = 900): array
    {
        if ($limit <= 0 || $staleSeconds <= 0) {
            throw new \InvalidArgumentException('Recovery limit and stale interval must be positive.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'intent_payload')
            ->from(Installer::TABLE_AUDIT_LOG)
            ->where('status IN (:inProgress, :recoveryRequired)')
            ->andWhere('updated_at <= :cutoff')
            ->andWhere('intent_payload IS NOT NULL')
            ->setParameter('inProgress', OperationStatus::InProgress->value)
            ->setParameter('recoveryRequired', OperationStatus::RecoveryRequired->value)
            ->setParameter('cutoff', $this->format($this->now()->modify(sprintf('-%d seconds', $staleSeconds))))
            ->orderBy('updated_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(min(1_000, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): OperationHandle => new OperationHandle(
                (int) $row['id'],
                OperationIntent::fromArray($this->decode((string) $row['intent_payload'])),
            ),
            $rows,
        );
    }

    public function recordObserverFailure(int $operationId, string $observerId): ObserverAuditReconciliationStatus
    {
        $observerId = trim($observerId);
        if ($operationId <= 0 || $observerId === '') {
            throw new \InvalidArgumentException('An observer failure requires an operation ID and observer ID.');
        }

        return $this->connection->transactional(function () use ($operationId, $observerId): ObserverAuditReconciliationStatus {
            $row = $this->observerFailureRow($operationId);
            if ($row === false) {
                return ObserverAuditReconciliationStatus::NotApplicable;
            }

            $status = OperationStatus::from((string) $row['status']);
            if (in_array($status, [OperationStatus::Failed, OperationStatus::Skipped], true)) {
                return ObserverAuditReconciliationStatus::NotApplicable;
            }
            if (!in_array($status, [OperationStatus::Completed, OperationStatus::CompletedWithObserverError], true)) {
                return ObserverAuditReconciliationStatus::Deferred;
            }

            $failures = $this->observerFailures($row);
            if (!in_array($observerId, $failures['observerIds'], true)) {
                $failures['observerIds'][] = $observerId;
            }

            $updated = $this->connection->update(Installer::TABLE_AUDIT_LOG, [
                'status' => OperationStatus::CompletedWithObserverError->value,
                'error_message' => $this->observerFailureMessage($failures),
                'durable_observer_failures' => $this->encode($failures),
                'updated_at' => $this->format($this->now()),
            ], ['id' => $operationId]);

            return $updated === 1
                ? ObserverAuditReconciliationStatus::Recorded
                : ObserverAuditReconciliationStatus::Deferred;
        });
    }

    public function resolveObserverFailures(int $operationId): ObserverAuditReconciliationStatus
    {
        if ($operationId <= 0) {
            return ObserverAuditReconciliationStatus::Deferred;
        }

        return $this->connection->transactional(function () use ($operationId): ObserverAuditReconciliationStatus {
            if ($this->deliveries->hasUnresolved($operationId)) {
                return ObserverAuditReconciliationStatus::Deferred;
            }
            if ($this->deliveries->hasDead($operationId)) {
                return ObserverAuditReconciliationStatus::NotApplicable;
            }

            $row = $this->observerFailureRow($operationId);
            if ($row === false) {
                return ObserverAuditReconciliationStatus::NotApplicable;
            }

            $operationStatus = OperationStatus::from((string) $row['status']);
            if (in_array($operationStatus, [OperationStatus::Completed, OperationStatus::Failed, OperationStatus::Skipped], true)) {
                return ObserverAuditReconciliationStatus::NotApplicable;
            }
            if ($operationStatus !== OperationStatus::CompletedWithObserverError) {
                return ObserverAuditReconciliationStatus::Deferred;
            }

            $encodedFailures = $row['durable_observer_failures'] ?? null;
            if (!is_string($encodedFailures) || trim($encodedFailures) === '') {
                return ObserverAuditReconciliationStatus::NotApplicable;
            }
            $failures = $this->observerFailures($row);
            if ($failures['observerIds'] === []) {
                return ObserverAuditReconciliationStatus::NotApplicable;
            }

            $error = $failures['baseError'];
            $status = $error === null ? OperationStatus::Completed : OperationStatus::CompletedWithObserverError;
            $updated = $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_AUDIT_LOG . ' SET status = ?, error_message = ?, durable_observer_failures = NULL, updated_at = ? WHERE id = ? AND status = ? AND durable_observer_failures = ? AND NOT EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE operation_id = ? AND status IN (?, ?, ?, ?, ?))',
                [
                    $status->value,
                    $error,
                    $this->format($this->now()),
                    $operationId,
                    OperationStatus::CompletedWithObserverError->value,
                    $encodedFailures,
                    $operationId,
                    OperationDeliveryStatus::Dead->value,
                    OperationDeliveryStatus::Prepared->value,
                    OperationDeliveryStatus::Pending->value,
                    OperationDeliveryStatus::Processing->value,
                    OperationDeliveryStatus::Retry->value,
                ],
            );

            return $updated === 1
                ? ObserverAuditReconciliationStatus::Recorded
                : ObserverAuditReconciliationStatus::Deferred;
        });
    }

    /** @return array<string, mixed>|false */
    private function observerFailureRow(int $operationId): array|false
    {
        $query = $this->connection->createQueryBuilder()
            ->select('status', 'error_message', 'durable_observer_failures')
            ->from(Installer::TABLE_AUDIT_LOG)
            ->where('id = :id')
            ->setParameter('id', $operationId);
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->forUpdate();
        }

        return $query->executeQuery()->fetchAssociative();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{baseError: ?string, observerIds: list<string>}
     */
    private function observerFailures(array $row): array
    {
        $encoded = $row['durable_observer_failures'] ?? null;
        if (!is_string($encoded) || trim($encoded) === '') {
            $error = $row['error_message'] ?? null;

            return [
                'baseError' => is_string($error) && trim($error) !== '' ? $error : null,
                'observerIds' => [],
            ];
        }

        $decoded = $this->decode($encoded);
        $baseError = $decoded['baseError'] ?? null;
        $observerIds = $decoded['observerIds'] ?? [];
        if (($baseError !== null && !is_string($baseError)) || !is_array($observerIds)) {
            throw new \UnexpectedValueException('Durable observer failure state is invalid.');
        }

        return [
            'baseError' => $baseError,
            'observerIds' => array_values(array_filter($observerIds, 'is_string')),
        ];
    }

    /** @param array{baseError: ?string, observerIds: list<string>} $failures */
    private function observerFailureMessage(array $failures): string
    {
        $messages = $failures['baseError'] === null ? [] : [$failures['baseError']];
        foreach ($failures['observerIds'] as $observerId) {
            $messages[] = sprintf('Durable observer "%s" did not complete.', $observerId);
        }

        return implode('; ', $messages);
    }

    /** @return array<string, mixed> */
    private function operationRow(
        OperationIntent $intent,
        OperationStatus $status,
        ?string $errorMessage = null,
        ?int $durationMs = null,
    ): array {
        return [
            'asset_id' => $intent->assetId,
            'asset_path_from' => $intent->sourcePath,
            'asset_path_to' => $intent->targetPath,
            'object_id' => $intent->objectId,
            'object_class' => $intent->objectClass,
            'rule_name' => $intent->ruleName,
            'trigger_type' => $intent->triggerType->value,
            'status' => $status->value,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'user_id' => $intent->actor->userId,
            'created_at' => $this->format($intent->createdAt),
        ];
    }

    private function deliveryOutcome(OperationStatus $status): ?OperationDeliveryOutcome
    {
        if ($this->isSuccess($status)) {
            return OperationDeliveryOutcome::Success;
        }

        return $status === OperationStatus::Failed ? OperationDeliveryOutcome::Failure : null;
    }

    private function isSuccess(OperationStatus $status): bool
    {
        return in_array($status, [OperationStatus::Completed, OperationStatus::CompletedWithObserverError], true);
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
            throw new \UnexpectedValueException('An operation intent must decode to an object.');
        }

        return $decoded;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function format(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
