<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Oronts\AssetPilotBundle\Exception\AssetDeletionFenceLostException;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Model\Element\ValidationException;

class DbalAssetDeletionFence implements AssetDeletionFenceInterface
{
    protected const int TARGET_BATCH = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $leaseSeconds = 900,
        private readonly ?ProjectionMarkerConnectionInterface $markerConnectionProvider = null,
    ) {}

    /**
     * The fence read must run on the same connection that publishes the dirty marker. Under a consumer-owned
     * ambient transaction that is a dedicated autocommit connection, so the LEFT JOIN sees a fence committed
     * after the consumer's REPEATABLE READ snapshot instead of the stale in-transaction view.
     */
    protected function markerConnection(): Connection
    {
        return $this->markerConnectionProvider?->forMarker() ?? $this->connection;
    }

    public function acquire(int $assetId, string $operation): ?string
    {
        $this->assertNoAmbientTransaction();
        $token = bin2hex(random_bytes(32));
        $now = $this->now();

        try {
            $this->connection->insert(Installer::TABLE_ASSET_DELETION_FENCE, [
                'asset_id' => $assetId,
                'owner_token' => $token,
                'operation' => $operation,
                'created_at' => $now,
                'heartbeat_at' => $now,
                'expires_at' => $this->leaseFrom($now),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $token;
    }

    public function refreshOrFail(int $assetId, string $ownerToken): void
    {
        $now = $this->now();
        $updated = $this->connection->update(
            Installer::TABLE_ASSET_DELETION_FENCE,
            ['heartbeat_at' => $now, 'expires_at' => $this->leaseFrom($now)],
            ['asset_id' => $assetId, 'owner_token' => $ownerToken],
        );
        if ($updated === 1) {
            return;
        }

        // MySQL/MariaDB reports zero changed rows when a refresh rewrites identical timestamps within the
        // same whole second, so a token-qualified existence check distinguishes a same-second no-op (still
        // owned = success) from an actually lost fence.
        $stillOwned = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_ASSET_DELETION_FENCE . ' WHERE asset_id = ? AND owner_token = ?',
            [$assetId, $ownerToken],
        ) === 1;
        if (!$stillOwned) {
            throw new AssetDeletionFenceLostException($assetId);
        }
    }

    public function release(int $assetId, string $ownerToken): void
    {
        $this->connection->delete(
            Installer::TABLE_ASSET_DELETION_FENCE,
            ['asset_id' => $assetId, 'owner_token' => $ownerToken],
        );
    }

    public function assertWritableTargets(array $targetIds): void
    {
        if ($targetIds === []) {
            return;
        }

        $connection = $this->markerConnection();
        foreach (array_chunk($targetIds, self::TARGET_BATCH) as $chunk) {
            $rows = $connection->executeQuery(
                'SELECT a.id AS id, CASE WHEN f.asset_id IS NULL THEN 0 ELSE 1 END AS fenced'
                . ' FROM assets a'
                . ' LEFT JOIN ' . Installer::TABLE_ASSET_DELETION_FENCE . ' f ON f.asset_id = a.id'
                . ' WHERE a.id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            )->fetchAllKeyValue();

            foreach ($chunk as $targetId) {
                if (!array_key_exists($targetId, $rows)) {
                    throw new ValidationException(sprintf(
                        'Cannot save: referenced asset %d no longer exists. Reload the element and remove or replace the reference.',
                        $targetId,
                    ));
                }
                if ((int) $rows[$targetId] === 1) {
                    throw new ValidationException(sprintf(
                        'Cannot save: referenced asset %d is being deleted. Reload the element and retry with an existing asset.',
                        $targetId,
                    ));
                }
            }
        }
    }

    public function expiredFenceCandidates(int $batchSize): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('f.asset_id')
            ->from(Installer::TABLE_ASSET_DELETION_FENCE, 'f')
            ->where('f.expires_at < :now')
            ->orWhere('NOT EXISTS (SELECT 1 FROM assets a WHERE a.id = f.asset_id)')
            ->orderBy('f.expires_at', 'ASC')
            ->addOrderBy('f.asset_id', 'ASC')
            ->setParameter('now', $this->now())
            ->setMaxResults($batchSize);

        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    }

    public function reapAsset(int $assetId): bool
    {
        $ownerToken = $this->connection->fetchOne(
            'SELECT owner_token FROM ' . Installer::TABLE_ASSET_DELETION_FENCE . ' WHERE asset_id = ?',
            [$assetId],
        );
        if ($ownerToken === false) {
            return false;
        }

        $assetExists = $this->connection->fetchOne('SELECT 1 FROM assets WHERE id = ?', [$assetId]) !== false;
        if (!$assetExists) {
            return $this->connection->delete(
                Installer::TABLE_ASSET_DELETION_FENCE,
                ['asset_id' => $assetId, 'owner_token' => $ownerToken],
            ) > 0;
        }

        return $this->connection->executeStatement(
            'DELETE FROM ' . Installer::TABLE_ASSET_DELETION_FENCE
            . ' WHERE asset_id = ? AND owner_token = ? AND expires_at < ?',
            [$assetId, $ownerToken, $this->now()],
        ) > 0;
    }

    protected function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    protected function leaseFrom(string $now): string
    {
        return (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->modify('+' . $this->leaseSeconds . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    private function assertNoAmbientTransaction(): void
    {
        if (!$this->connection->isAutoCommit() || $this->connection->isTransactionActive()) {
            throw new \LogicException(
                'An asset deletion fence cannot be claimed inside an ambient database transaction or on a '
                . 'non-autocommit connection: a tombstone hidden in an uncommitted transaction cannot fence other pods.',
            );
        }
    }
}
