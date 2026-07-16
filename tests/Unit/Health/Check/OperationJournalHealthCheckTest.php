<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Health\Check\OperationJournalHealthCheck;
use Oronts\AssetPilotBundle\Installer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OperationJournalHealthCheck::class)]
final class OperationJournalHealthCheckTest extends TestCase
{
    public const string NOW = '2030-01-01 12:00:00';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function healthyWhenNoJournalOrDeliveryWorkIsStale(): void
    {
        $this->journal(1, OperationStatus::InProgress, '2030-01-01 11:59:00');
        $this->delivery('recent', 1, OperationDeliveryStatus::Pending, '2030-01-01 11:59:00');

        $result = $this->check()->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame(0, $result->details['journal']['in_progress']);
        self::assertSame(0, $result->details['deliveries']['overdue']);
        self::assertSame(300, $result->details['recovery_after_seconds']);
    }

    #[Test]
    public function warnsWithActionableSamplesForStaleJournalAndOverdueDelivery(): void
    {
        $this->journal(2, OperationStatus::InProgress, '2030-01-01 11:50:00', 102);
        $this->journal(3, OperationStatus::RecoveryRequired, '2030-01-01 11:59:00', 103);
        $this->delivery('overdue', 2, OperationDeliveryStatus::Retry, '2030-01-01 11:50:00', error: 'broker unavailable');
        $this->delivery('recent', 3, OperationDeliveryStatus::Pending, '2030-01-01 11:59:00');

        $result = $this->check()->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertStringContainsString('Run operation recovery', $result->message);
        self::assertSame(1, $result->details['journal']['in_progress']);
        self::assertSame(0, $result->details['journal']['recovery_required']);
        self::assertSame('2030-01-01 11:50:00', $result->details['journal']['oldest_updated_at']);
        self::assertSame(2, $result->details['journal']['samples'][0]['id']);
        self::assertSame(102, $result->details['journal']['samples'][0]['asset_id']);
        self::assertSame(1, $result->details['deliveries']['overdue']);
        self::assertSame(0, $result->details['deliveries']['dead']);
        self::assertSame('overdue', $result->details['deliveries']['samples'][0]['id']);
        self::assertSame('broker unavailable', $result->details['deliveries']['samples'][0]['last_error']);
    }

    #[Test]
    public function isCriticalForRecoveryRequiredJournalOrDeadDelivery(): void
    {
        $this->journal(4, OperationStatus::RecoveryRequired, '2030-01-01 11:40:00', 104);
        $this->delivery('dead-letter', 4, OperationDeliveryStatus::Dead, '2030-01-01 11:58:00', error: 'observer removed');

        $result = $this->check()->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertStringContainsString('needs intervention', $result->message);
        self::assertSame(1, $result->details['journal']['recovery_required']);
        self::assertSame(1, $result->details['deliveries']['dead']);
        self::assertSame('dead-letter', $result->details['deliveries']['samples'][0]['id']);
        self::assertSame('observer removed', $result->details['deliveries']['samples'][0]['last_error']);
    }

    #[Test]
    public function expiredProcessingLeaseCountsAsOverdue(): void
    {
        $this->delivery(
            'expired-lease',
            5,
            OperationDeliveryStatus::Processing,
            '2030-01-01 12:30:00',
            lockedUntil: '2030-01-01 11:45:00',
        );

        $result = $this->check()->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertSame(1, $result->details['deliveries']['overdue']);
        self::assertSame('2030-01-01 11:45:00', $result->details['deliveries']['oldest_overdue_at']);
    }

    #[Test]
    public function queryFailureDegradesToWarning(): void
    {
        $this->connection->createSchemaManager()->dropTable(Installer::TABLE_OPERATION_DELIVERY);

        $result = $this->check()->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertStringContainsString('Could not inspect', $result->message);
        self::assertSame(['recovery_after_seconds' => 300], $result->details);
    }

    #[Test]
    public function rejectsANonPositiveRecoveryInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OperationJournalHealthCheck($this->connection, new NullLogger(), 0);
    }

    private function check(): OperationJournalHealthCheck
    {
        return new class ($this->connection, new NullLogger(), 300) extends OperationJournalHealthCheck {
            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(OperationJournalHealthCheckTest::NOW, new \DateTimeZone('UTC'));
            }
        };
    }

    private function journal(int $id, OperationStatus $status, string $updatedAt, int $assetId = 100): void
    {
        $this->connection->insert(Installer::TABLE_AUDIT_LOG, [
            'id' => $id,
            'asset_id' => $assetId,
            'status' => $status->value,
            'updated_at' => $updatedAt,
        ]);
    }

    private function delivery(
        string $id,
        int $operationId,
        OperationDeliveryStatus $status,
        string $availableAt,
        ?string $lockedUntil = null,
        ?string $error = null,
    ): void {
        $this->connection->insert(Installer::TABLE_OPERATION_DELIVERY, [
            'id' => $id,
            'operation_id' => $operationId,
            'observer_id' => 'properties',
            'outcome' => OperationDeliveryOutcome::Success->value,
            'status' => $status->value,
            'available_at' => $availableAt,
            'locked_until' => $lockedUntil,
            'last_error' => $error,
            'updated_at' => $availableAt,
        ]);
    }

    private function createTables(): void
    {
        $schema = new Schema();
        $audit = $schema->createTable(Installer::TABLE_AUDIT_LOG);
        $audit->addColumn('id', 'integer');
        $audit->addColumn('asset_id', 'integer');
        $audit->addColumn('status', 'string', ['length' => 50]);
        $audit->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $audit->setPrimaryKey(['id']);
        $delivery = $schema->createTable(Installer::TABLE_OPERATION_DELIVERY);
        $delivery->addColumn('id', 'string', ['length' => 64]);
        $delivery->addColumn('operation_id', 'integer');
        $delivery->addColumn('observer_id', 'string', ['length' => 191]);
        $delivery->addColumn('outcome', 'string', ['length' => 16]);
        $delivery->addColumn('status', 'string', ['length' => 20]);
        $delivery->addColumn('available_at', 'datetime');
        $delivery->addColumn('locked_until', 'datetime', ['notnull' => false]);
        $delivery->addColumn('last_error', 'text', ['notnull' => false]);
        $delivery->addColumn('updated_at', 'datetime');
        $delivery->setPrimaryKey(['id']);
        $manager = $this->connection->createSchemaManager();
        $manager->createTable($audit);
        $manager->createTable($delivery);
    }
}
