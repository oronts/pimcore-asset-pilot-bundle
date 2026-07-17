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
        private readonly AssetDependencyTargetExtractor $targetExtractor,
    ) {}

    public function markDirty(string $sourceType, int $sourceId): DependencySourceToken
    {
        $this->assertSource($sourceType, $sourceId);

        return $this->dirty($this->sourceKey($sourceType, $sourceId), $sourceType, $sourceId);
    }

    public function markPending(string $sourceType): DependencySourceToken
    {
        $this->assertSourceType($sourceType);

        return $this->dirty('pending:' . bin2hex(random_bytes(16)), $sourceType, null);
    }

    public function refresh(AbstractElement $source, DependencySourceToken $token): bool
    {
        $sourceType = ElementService::getElementType($source);
        $sourceId = (int) $source->getId();
        $this->assertSource($sourceType, $sourceId);
        $targetIds = $this->targetExtractor->extract($source);

        return $this->connection->transactional(function () use ($source, $sourceType, $sourceId, $targetIds, $token): bool {
            $tokenRow = $this->lockedSource($token->sourceKey);
            if ($tokenRow === false || (int) $tokenRow['revision'] !== $token->revision || (string) $tokenRow['state'] !== 'dirty') {
                return false;
            }

            $sourceKey = $this->sourceKey($sourceType, $sourceId);
            $revision = $sourceKey === $token->sourceKey
                ? $token->revision
                : $this->dirtyLocked($sourceKey, $sourceType, $sourceId)->revision;

            $this->connection->delete(Installer::TABLE_DEPENDENCY_EDGE, ['source_key' => $sourceKey]);
            foreach ($targetIds as $targetId) {
                $this->connection->insert(Installer::TABLE_DEPENDENCY_EDGE, [
                    'source_key' => $sourceKey,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'target_asset_id' => $targetId,
                ]);
            }

            $updated = $this->connection->update(Installer::TABLE_DEPENDENCY_SOURCE, [
                'state' => 'clean',
                'source_modified_at' => $source->getModificationDate(),
                'indexed_at' => $this->now(),
                'dirty_at' => null,
                'error_message' => null,
            ], ['source_key' => $sourceKey, 'revision' => $revision]);
            if ($updated !== 1) {
                throw new \RuntimeException('The dependency source changed while its projection was being refreshed.');
            }
            if ($sourceKey !== $token->sourceKey) {
                $this->connection->delete(Installer::TABLE_DEPENDENCY_SOURCE, [
                    'source_key' => $token->sourceKey,
                    'revision' => $token->revision,
                ]);
            }

            return true;
        });
    }

    public function remove(string $sourceType, int $sourceId, ?DependencySourceToken $token = null): void
    {
        $this->assertSource($sourceType, $sourceId);
        $sourceKey = $this->sourceKey($sourceType, $sourceId);

        $this->connection->transactional(function () use ($sourceKey, $token): void {
            $row = $this->lockedSource($sourceKey);
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
        $this->connection->delete(Installer::TABLE_DEPENDENCY_SOURCE, [
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

    private function dirty(string $sourceKey, string $sourceType, ?int $sourceId): DependencySourceToken
    {
        return $this->connection->transactional(fn (): DependencySourceToken => $this->dirtyLocked($sourceKey, $sourceType, $sourceId));
    }

    private function dirtyLocked(string $sourceKey, string $sourceType, ?int $sourceId): DependencySourceToken
    {
        $row = $this->lockedSource($sourceKey);
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
                $this->connection->insert(Installer::TABLE_DEPENDENCY_SOURCE, [
                    'source_key' => $sourceKey,
                    'source_modified_at' => null,
                    'indexed_at' => null,
                    ...$values,
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->dirtyLocked($sourceKey, $sourceType, $sourceId);
            }
        } else {
            $this->connection->update(Installer::TABLE_DEPENDENCY_SOURCE, $values, ['source_key' => $sourceKey]);
        }

        return new DependencySourceToken($sourceKey, $revision);
    }

    /** @return array<string, mixed>|false */
    private function lockedSource(string $sourceKey): array|false
    {
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(Installer::TABLE_DEPENDENCY_SOURCE)
            ->where('source_key = :sourceKey')
            ->setParameter('sourceKey', $sourceKey);
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
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
