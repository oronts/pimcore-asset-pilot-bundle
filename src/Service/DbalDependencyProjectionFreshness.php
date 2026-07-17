<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\DependencyProjectionStatus;

class DbalDependencyProjectionFreshness implements DependencyProjectionFreshnessInterface
{
    private const int ROW_ID = 1;

    public function __construct(private readonly Connection $connection) {}

    public function generation(): int
    {
        $this->ensureRow();

        return (int) $this->connection->fetchOne(
            'SELECT generation FROM ' . Installer::TABLE_DEPENDENCY_FRESHNESS . ' WHERE id = ?',
            [self::ROW_ID],
        );
    }

    public function status(): DependencyProjectionStatus
    {
        $this->ensureRow();
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . Installer::TABLE_DEPENDENCY_FRESHNESS . ' WHERE id = ?',
            [self::ROW_ID],
        );
        if ($row === false) {
            throw new \RuntimeException('Dependency projection freshness state is unavailable.');
        }

        return $this->statusFromRow($row);
    }

    public function beginRebuild(bool $restart = false): DependencyProjectionStatus
    {
        $this->ensureRow();

        return $this->connection->transactional(function () use ($restart): DependencyProjectionStatus {
            $row = $this->lockedRow();
            $state = DependencyProjectionState::from((string) $row['status']);
            if (!$restart) {
                if (in_array($state, [DependencyProjectionState::Building, DependencyProjectionState::Ready], true)) {
                    return $this->statusFromRow($row);
                }
                if ($state === DependencyProjectionState::Failed) {
                    $this->connection->update(Installer::TABLE_DEPENDENCY_FRESHNESS, [
                        'status' => DependencyProjectionState::Building->value,
                        'updated_at' => $this->now(),
                        'error_message' => null,
                    ], ['id' => self::ROW_ID]);

                    return $this->status();
                }
            }

            $generation = (int) $row['generation'] + 1;
            $now = $this->now();
            $this->connection->update(Installer::TABLE_DEPENDENCY_FRESHNESS, [
                'status' => DependencyProjectionState::Building->value,
                'generation' => $generation,
                'cursor_type' => 'object',
                'cursor_id' => 0,
                'started_at' => $now,
                'completed_at' => null,
                'updated_at' => $now,
                'error_message' => null,
            ], ['id' => self::ROW_ID]);

            return $this->status();
        });
    }

    public function advanceRebuild(string $sourceType, int $sourceId): void
    {
        if ($sourceId < 0 || !in_array($sourceType, ['object', 'document', 'asset', 'complete'], true)) {
            throw new \InvalidArgumentException('Invalid dependency projection rebuild cursor.');
        }

        $this->connection->update(Installer::TABLE_DEPENDENCY_FRESHNESS, [
            'cursor_type' => $sourceType,
            'cursor_id' => $sourceId,
            'updated_at' => $this->now(),
        ], ['id' => self::ROW_ID, 'status' => DependencyProjectionState::Building->value]);
    }

    public function completeRebuild(): DependencyProjectionStatus
    {
        $this->ensureRow();

        return $this->connection->transactional(function (): DependencyProjectionStatus {
            $row = $this->lockedRow();
            $generation = (int) $row['generation'];
            $this->connection->executeStatement(
                'DELETE FROM ' . Installer::TABLE_DEPENDENCY_EDGE . ' WHERE source_key IN (SELECT source_key FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE generation <> ?)',
                [$generation],
            );
            $this->connection->executeStatement(
                'DELETE FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE generation <> ?',
                [$generation],
            );

            $dirty = $this->dirtyCount();
            if ($dirty > 0) {
                $this->connection->update(Installer::TABLE_DEPENDENCY_FRESHNESS, [
                    'updated_at' => $this->now(),
                    'error_message' => sprintf('%d dirty source(s) still require projection.', $dirty),
                ], ['id' => self::ROW_ID]);

                return $this->status();
            }

            $now = $this->now();
            $this->connection->update(Installer::TABLE_DEPENDENCY_FRESHNESS, [
                'status' => DependencyProjectionState::Ready->value,
                'cursor_type' => null,
                'cursor_id' => 0,
                'source_count' => $this->sourceCount(),
                'edge_count' => $this->edgeCount(),
                'completed_at' => $now,
                'updated_at' => $now,
                'error_message' => null,
            ], ['id' => self::ROW_ID]);

            return $this->status();
        });
    }

    public function failRebuild(string $error): void
    {
        $error = trim($error);
        if ($error === '') {
            throw new \InvalidArgumentException('A failed dependency rebuild requires an error.');
        }
        $this->ensureRow();
        $this->connection->update(Installer::TABLE_DEPENDENCY_FRESHNESS, [
            'status' => DependencyProjectionState::Failed->value,
            'updated_at' => $this->now(),
            'error_message' => mb_substr($error, 0, 1000),
        ], ['id' => self::ROW_ID]);
    }

    private function ensureRow(): void
    {
        if ($this->connection->fetchOne(
            'SELECT id FROM ' . Installer::TABLE_DEPENDENCY_FRESHNESS . ' WHERE id = ?',
            [self::ROW_ID],
        ) !== false) {
            return;
        }

        $now = $this->now();
        try {
            $this->connection->insert(Installer::TABLE_DEPENDENCY_FRESHNESS, [
                'id' => self::ROW_ID,
                'status' => DependencyProjectionState::BootstrapRequired->value,
                'generation' => 0,
                'cursor_type' => null,
                'cursor_id' => 0,
                'source_count' => 0,
                'edge_count' => 0,
                'started_at' => null,
                'completed_at' => null,
                'updated_at' => $now,
                'error_message' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
        }
    }

    /** @return array<string, mixed> */
    private function lockedRow(): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(Installer::TABLE_DEPENDENCY_FRESHNESS)
            ->where('id = :id')
            ->setParameter('id', self::ROW_ID);
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->forUpdate();
        }
        $row = $query->executeQuery()->fetchAssociative();
        if ($row === false) {
            throw new \RuntimeException('Dependency projection freshness state is unavailable.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function statusFromRow(array $row): DependencyProjectionStatus
    {
        return new DependencyProjectionStatus(
            DependencyProjectionState::from((string) $row['status']),
            (int) $row['generation'],
            $this->dirtyCount(),
            $this->sourceCount(),
            $this->edgeCount(),
            $row['cursor_type'] === null ? null : (string) $row['cursor_type'],
            (int) $row['cursor_id'],
            $this->date($row['started_at']),
            $this->date($row['completed_at']),
            $row['error_message'] === null ? null : (string) $row['error_message'],
        );
    }

    private function dirtyCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE state = ?',
            ['dirty'],
        );
    }

    private function sourceCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . " WHERE state = 'clean'");
    }

    private function edgeCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_DEPENDENCY_EDGE);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) && $value !== ''
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : null;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
