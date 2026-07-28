<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\DependencyReferenceSnapshot;
use Oronts\AssetPilotBundle\Model\DependencySourceToken;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Element\Service as ElementService;

class DbalDependencyProjection implements DependencyProjectionInterface
{
    private const array SOURCE_TYPES = [
        PimcoreSchema::ELEMENT_TYPE_OBJECT,
        PimcoreSchema::ELEMENT_TYPE_DOCUMENT,
        PimcoreSchema::ELEMENT_TYPE_ASSET,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly DependencyProjectionFreshnessInterface $freshness,
        private readonly AssetDependencyTargetExtractorInterface $targetExtractor,
        private readonly ?ProjectionMarkerConnectionInterface $markerConnectionProvider = null,
    ) {}

    public function markDirty(string $sourceType, int $sourceId): DependencySourceToken
    {
        $this->assertSource($sourceType, $sourceId);

        return $this->dirty($this->markerConnection(), $this->sourceKey($sourceType, $sourceId), $sourceType, $sourceId);
    }

    public function markPending(string $sourceType): DependencySourceToken
    {
        $this->assertSourceType($sourceType);

        return $this->dirty($this->markerConnection(), 'pending:' . bin2hex(random_bytes(16)), $sourceType, null);
    }

    /**
     * The connection that publishes the dirty marker and edges (and that the fence read consults). Under a
     * consumer-owned ambient transaction this is a dedicated autocommit connection so the marker/edge commit
     * immediately and a concurrent deleter's snapshot can see them; otherwise it is the primary connection.
     */
    protected function markerConnection(): Connection
    {
        return $this->markerConnectionProvider?->forMarker() ?? $this->connection;
    }

    public function refresh(AbstractElement $source, DependencySourceToken $token): bool
    {
        $sourceType = ElementService::getElementType($source);
        $sourceId = (int) $source->getId();
        $this->assertSource($sourceType, $sourceId);
        $extraction = $this->targetExtractor->extract($source);
        $targetIds = $extraction->targetIds;
        $marker = $this->markerConnection();

        return $marker->transactional(function () use ($marker, $source, $sourceType, $sourceId, $targetIds, $extraction, $token): bool {
            $tokenRow = $this->lockedSource($marker, $token->sourceKey);
            if ($tokenRow === false || (int) $tokenRow['revision'] !== $token->revision || (string) $tokenRow['state'] !== 'dirty') {
                return false;
            }

            $sourceKey = $this->sourceKey($sourceType, $sourceId);
            $revision = $sourceKey === $token->sourceKey
                ? $token->revision
                : $this->dirtyLocked($marker, $sourceKey, $sourceType, $sourceId)->revision;

            $marker->delete(Installer::TABLE_DEPENDENCY_EDGE, ['source_key' => $sourceKey]);
            foreach ($targetIds as $targetId) {
                $marker->insert(Installer::TABLE_DEPENDENCY_EDGE, [
                    'source_key' => $sourceKey,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'target_asset_id' => $targetId,
                ]);
            }

            // Fail closed: an incomplete traversal keeps the source dirty, so a missing edge cannot yield a Safe delete verdict.
            $updated = $marker->update(Installer::TABLE_DEPENDENCY_SOURCE, $extraction->complete ? [
                'state' => 'clean',
                'source_modified_at' => $source->getModificationDate(),
                'indexed_at' => $this->now(),
                'dirty_at' => null,
                'error_message' => null,
            ] : [
                'state' => 'dirty',
                'source_modified_at' => $source->getModificationDate(),
                'indexed_at' => $this->now(),
                'dirty_at' => $this->now(),
                'error_message' => 'incomplete classification-store traversal',
            ], ['source_key' => $sourceKey, 'revision' => $revision]);
            if ($updated !== 1) {
                throw new \RuntimeException('The dependency source changed while its projection was being refreshed.');
            }
            if ($sourceKey !== $token->sourceKey) {
                $marker->delete(Installer::TABLE_DEPENDENCY_SOURCE, [
                    'source_key' => $token->sourceKey,
                    'revision' => $token->revision,
                ]);
            }

            return true;
        });
    }

    public function retainDirtyForCommit(string $sourceType, int $sourceId, DependencySourceToken $token): DependencySourceToken
    {
        $this->assertSource($sourceType, $sourceId);
        $realKey = $this->sourceKey($sourceType, $sourceId);

        if ($token->sourceKey === $realKey) {
            return $token;
        }

        $marker = $this->markerConnection();

        return $marker->transactional(function () use ($marker, $realKey, $sourceType, $sourceId, $token): DependencySourceToken {
            $newToken = $this->dirtyLocked($marker, $realKey, $sourceType, $sourceId);
            $marker->delete(Installer::TABLE_DEPENDENCY_SOURCE, [
                'source_key' => $token->sourceKey,
                'revision' => $token->revision,
            ]);

            return $newToken;
        });
    }

    public function staleDirtySources(string $dirtyBefore, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT source_type, source_id FROM ' . Installer::TABLE_DEPENDENCY_SOURCE
            . " WHERE state = 'dirty' AND source_id IS NOT NULL AND dirty_at IS NOT NULL AND dirty_at < ?"
            . ' ORDER BY dirty_at ASC LIMIT ' . max(1, $limit),
            [$dirtyBefore],
        );

        return array_map(
            static fn (array $row): array => ['sourceType' => (string) $row['source_type'], 'sourceId' => (int) $row['source_id']],
            $rows,
        );
    }

    public function pruneStaleOrphanPendingSources(string $dirtyBefore): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM ' . Installer::TABLE_DEPENDENCY_SOURCE
            . " WHERE state = 'dirty' AND source_id IS NULL AND dirty_at IS NOT NULL AND dirty_at < ?",
            [$dirtyBefore],
        );
    }

    public function remove(string $sourceType, int $sourceId, ?DependencySourceToken $token = null): void
    {
        $this->assertSource($sourceType, $sourceId);
        $sourceKey = $this->sourceKey($sourceType, $sourceId);

        $this->connection->transactional(function () use ($sourceKey, $token): void {
            $row = $this->lockedSource($this->connection, $sourceKey);
            if ($row === false) {
                return;
            }
            if ($token !== null && ($token->sourceKey !== $sourceKey || (int) $row['revision'] !== $token->revision)) {
                return;
            }

            $this->connection->delete(Installer::TABLE_DEPENDENCY_EDGE, ['source_key' => $sourceKey]);
            $this->connection->delete(Installer::TABLE_DEPENDENCY_SOURCE, ['source_key' => $sourceKey]);
        });
    }

    public function discard(DependencySourceToken $token): void
    {
        $this->markerConnection()->delete(Installer::TABLE_DEPENDENCY_SOURCE, [
            'source_key' => $token->sourceKey,
            'revision' => $token->revision,
        ]);
    }

    public function hasAssetReference(int $assetId): bool
    {
        if ($assetId <= 0) {
            return false;
        }

        return $this->connection->fetchOne(
            'SELECT 1 FROM ' . Installer::TABLE_DEPENDENCY_EDGE . ' WHERE target_asset_id = ? LIMIT 1',
            [$assetId],
        ) !== false;
    }

    public function referenceSnapshot(int $assetId): DependencyReferenceSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT'
            . ' (CASE WHEN EXISTS (SELECT 1 FROM ' . Installer::TABLE_DEPENDENCY_EDGE . ' WHERE target_asset_id = ?) THEN 1 ELSE 0 END) AS referenced,'
            . ' (CASE WHEN EXISTS (SELECT 1 FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . " WHERE state = 'dirty') THEN 1 ELSE 0 END) AS dirty",
            [$assetId],
        );

        return new DependencyReferenceSnapshot(
            (int) ($row['referenced'] ?? 0) === 1,
            (int) ($row['dirty'] ?? 0) === 1,
        );
    }

    private function dirty(Connection $connection, string $sourceKey, string $sourceType, ?int $sourceId): DependencySourceToken
    {
        return $connection->transactional(fn (): DependencySourceToken => $this->dirtyLocked($connection, $sourceKey, $sourceType, $sourceId));
    }

    private function dirtyLocked(Connection $connection, string $sourceKey, string $sourceType, ?int $sourceId): DependencySourceToken
    {
        $row = $this->lockedSource($connection, $sourceKey);
        $revision = $row === false ? 1 : (int) $row['revision'] + 1;
        $values = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'state' => 'dirty',
            'revision' => $revision,
            'generation' => $this->freshness->generation(),
            'dirty_at' => $this->now(),
            'error_message' => null,
        ];
        if ($row === false) {
            try {
                $connection->insert(Installer::TABLE_DEPENDENCY_SOURCE, [
                    'source_key' => $sourceKey,
                    'source_modified_at' => null,
                    'indexed_at' => null,
                    ...$values,
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->dirtyLocked($connection, $sourceKey, $sourceType, $sourceId);
            }
        } else {
            $connection->update(Installer::TABLE_DEPENDENCY_SOURCE, $values, ['source_key' => $sourceKey]);
        }

        return new DependencySourceToken($sourceKey, $revision);
    }

    /** @return array<string, mixed>|false */
    private function lockedSource(Connection $connection, string $sourceKey): array|false
    {
        $query = $connection->createQueryBuilder()
            ->select('*')
            ->from(Installer::TABLE_DEPENDENCY_SOURCE)
            ->where('source_key = :sourceKey')
            ->setParameter('sourceKey', $sourceKey);
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->forUpdate();
        }

        return $query->executeQuery()->fetchAssociative();
    }

    private function assertSource(string $sourceType, int $sourceId): void
    {
        $this->assertSourceType($sourceType);
        if ($sourceId <= 0) {
            throw new \InvalidArgumentException('A dependency projection source ID must be positive.');
        }
    }

    private function assertSourceType(string $sourceType): void
    {
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported dependency source type "%s".', $sourceType));
        }
    }

    private function sourceKey(string $sourceType, int $sourceId): string
    {
        return $sourceType . ':' . $sourceId;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
