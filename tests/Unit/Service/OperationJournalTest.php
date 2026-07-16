<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Oronts\AssetPilotBundle\Observer\DurableOperationObserverInterface;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStore;
use Oronts\AssetPilotBundle\Service\OperationJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationJournal::class)]
final class OperationJournalTest extends TestCase
{
    private Connection $connection;
    private OperationJournal $journal;
    private OperationDeliveryStore $deliveries;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createAuditTable($this->connection);
        OperationDeliveryStoreTest::createTable($this->connection);
        $this->deliveries = new OperationDeliveryStore($this->connection);
        $this->journal = new OperationJournal(
            $this->connection,
            new OperationObserverRegistry([$this->observer()]),
            $this->deliveries,
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function beginAtomicallyPersistsIntentAndPreparedDeliveries(): void
    {
        $operation = $this->journal->begin($this->intent());
        $row = $this->auditRow($operation->operationId);

        self::assertSame(OperationStatus::InProgress->value, $row['status']);
        self::assertSame(OperationKind::Move->value, $row['operation_kind']);
        self::assertSame('user', $row['actor_type']);
        self::assertSame(12, (int) $row['user_id']);
        self::assertSame(1, (int) $row['schema_version']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_DELIVERY));
    }

    #[Test]
    public function aDeliveryInsertFailureRollsBackTheAuditIntent(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createAuditTable($connection);
        $journal = new OperationJournal(
            $connection,
            new OperationObserverRegistry([$this->observer()]),
            new OperationDeliveryStore($connection),
        );

        try {
            $journal->begin($this->intent());
            self::fail('The missing delivery table must fail the transaction.');
        } catch (\Throwable) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_AUDIT_LOG));
        } finally {
            $connection->close();
        }
    }

    #[Test]
    public function successCompletionActivatesOnlySuccessDeliveriesAndIsIdempotent(): void
    {
        $operation = $this->journal->begin($this->intent());

        self::assertTrue($this->journal->complete($operation, OperationStatus::Completed, durationMs: 31));
        self::assertTrue($this->journal->complete($operation, OperationStatus::Completed, durationMs: 31));
        $row = $this->auditRow($operation->operationId);
        self::assertSame(OperationStatus::Completed->value, $row['status']);
        self::assertSame(31, (int) $row['duration_ms']);
        self::assertNotNull($row['committed_at']);
        self::assertSame(1, count($this->deliveries->due()));
        self::assertSame(
            [OperationDeliveryStatus::Cancelled->value, OperationDeliveryStatus::Pending->value],
            $this->connection->fetchFirstColumn('SELECT status FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' ORDER BY status'),
        );

        $this->expectException(\LogicException::class);
        $this->journal->complete($operation, OperationStatus::Failed, 'Wrong outcome.');
    }

    #[Test]
    public function recoveryRequiredKeepsPreparedWorkAndCanLaterResolveAsFailure(): void
    {
        $operation = $this->journal->begin($this->intent());
        self::assertTrue($this->journal->complete($operation, OperationStatus::RecoveryRequired, 'State is uncertain.'));
        self::assertTrue($this->deliveries->hasUnresolved($operation->operationId));
        self::assertSame([], $this->deliveries->due());

        $this->connection->update(Installer::TABLE_AUDIT_LOG, ['updated_at' => '2000-01-01 00:00:00'], ['id' => $operation->operationId]);
        $recoverable = $this->journal->recoverable(10, 1);
        self::assertCount(1, $recoverable);
        self::assertEquals($operation->intent, $recoverable[0]->intent);

        self::assertTrue($this->journal->complete($recoverable[0], OperationStatus::Failed, 'Mutation did not commit.'));
        self::assertSame(OperationStatus::Failed->value, $this->auditRow($operation->operationId)['status']);
        self::assertSame(1, count($this->deliveries->due()));
    }

    #[Test]
    public function deadObserverCanMarkACommittedOperationWithoutChangingItsOutcome(): void
    {
        $operation = $this->journal->begin($this->intent());
        $this->journal->complete($operation, OperationStatus::Completed);

        self::assertTrue($this->journal->recordObserverFailure($operation->operationId, 'Observer exhausted retries.'));
        $row = $this->auditRow($operation->operationId);
        self::assertSame(OperationStatus::CompletedWithObserverError->value, $row['status']);
        self::assertSame('Observer exhausted retries.', $row['error_message']);
        self::assertTrue($this->journal->recordObserverFailure($operation->operationId, 'Delivery is dead.'));
        self::assertSame('Observer exhausted retries.; Delivery is dead.', $this->auditRow($operation->operationId)['error_message']);
    }

    #[Test]
    public function retriedDeliveryKeepsItsWarningUntilSuccessfulCompletion(): void
    {
        $operation = $this->journal->begin($this->intent());
        $this->journal->complete($operation, OperationStatus::Completed);
        $deliveryId = $this->deliveries->due()[0];
        $delivery = $this->deliveries->claim($deliveryId, 'worker-one', 300);
        self::assertNotNull($delivery);
        self::assertTrue($this->deliveries->markDead($delivery, 'unavailable'));
        self::assertTrue($this->journal->recordObserverFailure(
            $operation->operationId,
            'Durable observer "test" did not complete.',
        ));

        self::assertSame(1, $this->deliveries->requeueDead($this->deliveries->dead()));
        self::assertFalse($this->journal->resolveObserverFailures($operation->operationId));
        self::assertSame(
            OperationStatus::CompletedWithObserverError->value,
            $this->auditRow($operation->operationId)['status'],
        );

        $retry = $this->deliveries->claim($deliveryId, 'worker-two', 300);
        self::assertNotNull($retry);
        self::assertTrue($this->deliveries->markDelivered($retry));
        self::assertTrue($this->journal->resolveObserverFailures($operation->operationId));
        $row = $this->auditRow($operation->operationId);
        self::assertSame(OperationStatus::Completed->value, $row['status']);
        self::assertNull($row['error_message']);
    }

    /** @return array<string, mixed> */
    private function auditRow(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . Installer::TABLE_AUDIT_LOG . ' WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function intent(): OperationIntent
    {
        return new OperationIntent(
            OperationKind::Move,
            7,
            '/source/a.jpg',
            '/target/a.jpg',
            9,
            'Product',
            'images',
            TriggerType::Manual,
            ActorContext::user(12),
            ['firstAssignment' => false],
            createdAt: new \DateTimeImmutable('2026-07-15 10:00:00 UTC'),
        );
    }

    private function observer(): DurableOperationObserverInterface
    {
        return new class () implements DurableOperationObserverInterface {
            public function id(): string
            {
                return 'test';
            }

            public function requiredAssetPermission(): ?string
            {
                return null;
            }

            public function prepare(OperationIntent $intent): iterable
            {
                return [
                    new PreparedDelivery($this->id(), 'success', OperationDeliveryOutcome::Success),
                    new PreparedDelivery($this->id(), 'failure', OperationDeliveryOutcome::Failure),
                ];
            }

            public function deliver(DeliveryEnvelope $delivery): void {}
        };
    }

    private static function createAuditTable(Connection $connection): void
    {
        $schema = new Schema();
        $table = $schema->createTable(Installer::TABLE_AUDIT_LOG);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('asset_id', 'integer');
        $table->addColumn('asset_path_from', 'string', ['length' => 765]);
        $table->addColumn('asset_path_to', 'string', ['length' => 765]);
        $table->addColumn('object_id', 'integer');
        $table->addColumn('object_class', 'string', ['length' => 255]);
        $table->addColumn('rule_name', 'string', ['length' => 255]);
        $table->addColumn('trigger_type', 'string', ['length' => 50]);
        $table->addColumn('status', 'string', ['length' => 50]);
        $table->addColumn('error_message', 'text', ['notnull' => false]);
        $table->addColumn('duration_ms', 'integer', ['notnull' => false]);
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime');
        $table->addColumn('operation_kind', 'string', ['length' => 20, 'notnull' => false]);
        $table->addColumn('actor_type', 'string', ['length' => 20, 'notnull' => false]);
        $table->addColumn('parent_audit_id', 'integer', ['notnull' => false]);
        $table->addColumn('intent_payload', 'text', ['notnull' => false]);
        $table->addColumn('schema_version', 'integer', ['notnull' => false]);
        $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $table->addColumn('committed_at', 'datetime', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $connection->createSchemaManager()->createTable($table);
    }
}
