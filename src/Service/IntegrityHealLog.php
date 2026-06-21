<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * The owned record of every integrity heal: what was rolled back, from which version to which, and
 * the verdict that triggered it. It is the audit trail AND the undo source — undo restores
 * `from_version` (the pre-heal state), because a version that renders can still be the wrong content.
 */
class IntegrityHealLog
{
    public const string TABLE = 'asset_pilot_integrity_log';

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_HEALED = 'healed';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_UNRECOVERABLE = 'unrecoverable';
    public const string STATUS_UNDONE = 'undone';

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
    ) {}

    public function record(int $assetId, ?int $fromVersion, ?int $toVersion, string $checker, string $status): void
    {
        try {
            $this->connection->insert(self::TABLE, [
                'asset_id' => $assetId,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'checker' => $checker,
                'status' => $status,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to write integrity heal log: {error}', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Open a `pending` heal row BEFORE the destructive restore and return its id, so a healed asset can
     * never exist without an undo source. Returns null if the row cannot be written, signalling the
     * caller to abort the heal while the asset is still untouched.
     */
    public function beginHeal(int $assetId, ?int $fromVersion, ?int $toVersion, string $checker): ?int
    {
        try {
            $this->connection->insert(self::TABLE, [
                'asset_id' => $assetId,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'checker' => $checker,
                'status' => self::STATUS_PENDING,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return (int) $this->connection->lastInsertId();
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to open integrity heal log row: {error}', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Promote a pending row to `healed` once the restore has succeeded (it is now undoable). Returns
     * false if the promotion could not be persisted, so the caller can surface that the heal, though
     * applied to the binary, may not be undoable (the row stays `pending`, which findUndoable ignores).
     */
    public function commitHeal(int $id): bool
    {
        try {
            $this->connection->update(self::TABLE, ['status' => self::STATUS_HEALED], ['id' => $id]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to commit integrity heal log {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Mark a pending row `failed` when the restore threw, so it is never offered as undoable. */
    public function failHeal(int $id): void
    {
        try {
            $this->connection->update(self::TABLE, ['status' => self::STATUS_FAILED], ['id' => $id]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to mark integrity heal {id} failed: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The most recent heal of this asset that can still be undone (a `healed` row carrying the
     * pre-heal version to roll back to).
     *
     * @return array{id: int, from_version: ?int, to_version: ?int}|null
     */
    public function findUndoable(int $assetId): ?array
    {
        try {
            $row = $this->connection->createQueryBuilder()
                ->select('id', 'from_version', 'to_version')
                ->from(self::TABLE)
                ->where('asset_id = :assetId')
                ->andWhere('status = :status')
                ->andWhere('from_version IS NOT NULL')
                ->setParameter('assetId', $assetId)
                ->setParameter('status', self::STATUS_HEALED)
                ->orderBy('id', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($row === false) {
                return null;
            }

            return [
                'id' => (int) $row['id'],
                'from_version' => $row['from_version'] !== null ? (int) $row['from_version'] : null,
                'to_version' => $row['to_version'] !== null ? (int) $row['to_version'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to read integrity heal log: {error}', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The status of this asset's most recent heal-log entry, or null if it has none. Used to notify
     * only on the transition into `unrecoverable`, so a repeated scan of a still-broken asset does
     * not re-alert on every run.
     */
    public function latestStatus(int $assetId): ?string
    {
        try {
            $status = $this->connection->createQueryBuilder()
                ->select('status')
                ->from(self::TABLE)
                ->where('asset_id = :assetId')
                ->setParameter('assetId', $assetId)
                ->orderBy('id', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            return $status === false ? null : (string) $status;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to read latest integrity status: {error}', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function markUndone(int $id): void
    {
        try {
            $this->connection->update(self::TABLE, ['status' => self::STATUS_UNDONE], ['id' => $id]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to mark heal {id} undone: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
