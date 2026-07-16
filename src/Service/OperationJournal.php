<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;

final class OperationJournal implements OperationJournalInterface
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
            if ($currentStatus !== $status) {
                $updated = $this->connection->update(Installer::TABLE_AUDIT_LOG, [
                    ...$this->operationRow($operation->intent, $status, $errorMessage, $durationMs),
                    'updated_at' => $now,
                    'committed_at' => $this->isSuccess($status) ? $now : null,
                ], ['id' => $operation->operationId]);
                if ($updated !== 1) {
                    throw new \RuntimeException(sprintf('Operation journal entry %d could not be completed.', $operation->operationId));
                }
            }

            $outcome = $this->deliveryOutcome($status);
            if ($outcome !== null) {
                $this->deliveries->activateForOutcome($operation->operationId, $outcome);
            }

            return true;
        });
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

    public function recordObserverFailure(int $operationId, string $error): bool
    {
        if ($operationId <= 0 || trim($error) === '') {
            throw new \InvalidArgumentException('An observer failure requires an operation ID and error.');
        }

        return $this->connection->transactional(function () use ($operationId, $error): bool {
            $row = $this->connection->fetchAssociative(
                'SELECT status, error_message FROM ' . Installer::TABLE_AUDIT_LOG . ' WHERE id = ?',
                [$operationId],
            );
            if ($row === false) {
                return false;
            }

            $status = OperationStatus::from((string) $row['status']);
            if (!in_array($status, [OperationStatus::Completed, OperationStatus::CompletedWithObserverError], true)) {
                return false;
            }

            $previous = trim((string) ($row['error_message'] ?? ''));
            $message = $previous === '' ? $error : $previous . '; ' . $error;

            return $this->connection->update(Installer::TABLE_AUDIT_LOG, [
                'status' => OperationStatus::CompletedWithObserverError->value,
                'error_message' => $message,
                'updated_at' => $this->format($this->now()),
            ], ['id' => $operationId]) === 1;
        });
    }

    public function resolveObserverFailures(int $operationId): bool
    {
        if ($operationId <= 0) {
            return false;
        }

        return $this->connection->transactional(function () use ($operationId): bool {
            if ($this->deliveries->hasDead($operationId) || $this->deliveries->hasUnresolved($operationId)) {
                return false;
            }

            $row = $this->connection->fetchAssociative(
                'SELECT status, error_message FROM ' . Installer::TABLE_AUDIT_LOG . ' WHERE id = ?',
                [$operationId],
            );
            if ($row === false || (string) $row['status'] !== OperationStatus::CompletedWithObserverError->value) {
                return false;
            }

            $previous = trim((string) ($row['error_message'] ?? ''));
            $remaining = array_values(array_filter(
                $previous === '' ? [] : explode('; ', $previous),
                static fn (string $error): bool => preg_match('/^Durable observer "[^"]+" did not complete\.$/D', $error) !== 1,
            ));
            if (count($remaining) === ($previous === '' ? 0 : count(explode('; ', $previous)))) {
                return false;
            }

            $error = $remaining === [] ? null : implode('; ', $remaining);
            $status = $remaining === [] ? OperationStatus::Completed : OperationStatus::CompletedWithObserverError;

            return $this->connection->executeStatement(
                'UPDATE ' . Installer::TABLE_AUDIT_LOG . ' SET status = ?, error_message = ?, updated_at = ? WHERE id = ? AND status = ? AND error_message = ? AND NOT EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE operation_id = ? AND status IN (?, ?, ?, ?, ?))',
                [
                    $status->value,
                    $error,
                    $this->format($this->now()),
                    $operationId,
                    OperationStatus::CompletedWithObserverError->value,
                    $previous,
                    $operationId,
                    OperationDeliveryStatus::Dead->value,
                    OperationDeliveryStatus::Prepared->value,
                    OperationDeliveryStatus::Pending->value,
                    OperationDeliveryStatus::Processing->value,
                    OperationDeliveryStatus::Retry->value,
                ],
            ) === 1;
        });
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
