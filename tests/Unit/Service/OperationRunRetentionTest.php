<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Service\OperationRunRetention;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationRunRetention::class)]
final class OperationRunRetentionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE ' . Installer::TABLE_OPERATION_RUN . ' (id VARCHAR(32) PRIMARY KEY, status VARCHAR(32) NOT NULL, updated_at DATETIME NOT NULL, retry_of VARCHAR(32) NULL)');
        $this->connection->executeStatement('CREATE TABLE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id VARCHAR(32) NOT NULL)');
    }

    #[Test]
    public function prunesOnlyExpiredTerminalLeavesAndTheirItems(): void
    {
        $this->insertRun('old-completed', OperationRunStatus::Completed, '2026-01-01 00:00:00');
        $this->insertRun('old-active', OperationRunStatus::Running, '2026-01-01 00:00:00');
        $this->insertRun('recent-completed', OperationRunStatus::Completed, '2026-07-14 00:00:00');
        $this->insertRun('retry-parent', OperationRunStatus::Failed, '2026-01-01 00:00:00');
        $this->insertRun('retry-child', OperationRunStatus::Completed, '2026-01-02 00:00:00', 'retry-parent');

        $retention = new OperationRunRetention(
            $this->connection,
            30,
            100,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-07-15 12:00:00'),
        );

        self::assertSame(2, $retention->prune());
        self::assertSame(['old-active', 'recent-completed', 'retry-parent'], $this->runIds());
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN_ITEM));

        self::assertSame(1, $retention->prune());
        self::assertSame(['old-active', 'recent-completed'], $this->runIds());
    }

    #[Test]
    public function retentionCutoffFallsBackToUtcWhenNoClockIsInjected(): void
    {
        $retention = new OperationRunRetention($this->connection, 30, 100);

        $now = (new \ReflectionMethod(OperationRunRetention::class, 'now'))->invoke($retention);

        self::assertSame('UTC', $now->getTimezone()->getName(), 'the retention cutoff must anchor to UTC to match UTC-persisted run timestamps');
    }

    #[Test]
    public function respectsTheConfiguredBatchLimit(): void
    {
        $this->insertRun('old-one', OperationRunStatus::Completed, '2026-01-01 00:00:00');
        $this->insertRun('old-two', OperationRunStatus::Completed, '2026-01-02 00:00:00');
        $retention = new OperationRunRetention(
            $this->connection,
            30,
            1,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-07-15 12:00:00'),
        );

        self::assertSame(1, $retention->prune());
        self::assertCount(1, $this->runIds());
    }

    private function insertRun(string $id, OperationRunStatus $status, string $updatedAt, ?string $retryOf = null): void
    {
        $this->connection->insert(Installer::TABLE_OPERATION_RUN, [
            'id' => $id,
            'status' => $status->value,
            'updated_at' => $updatedAt,
            'retry_of' => $retryOf,
        ]);
        $this->connection->insert(Installer::TABLE_OPERATION_RUN_ITEM, ['run_id' => $id]);
    }

    /** @return list<string> */
    private function runIds(): array
    {
        return $this->connection->fetchFirstColumn('SELECT id FROM ' . Installer::TABLE_OPERATION_RUN . ' ORDER BY id');
    }
}
