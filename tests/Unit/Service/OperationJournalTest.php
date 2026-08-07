<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\ObserverAuditReconciliationStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Oronts\AssetPilotBundle\Observer\DurableOperationObserverInterface;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStore;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStoreInterface;
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
    public function repeatedRecoveryRequiredClassificationPersistsTheNewReasonAndBumpsUpdatedAt(): void
    {
        $operation = $this->journal->begin($this->intent());
        self::assertTrue($this->journal->complete($operation, OperationStatus::RecoveryRequired, 'first diagnosis'));
        self::assertSame('first diagnosis', $this->auditRow($operation->operationId)['error_message']);

        // Simulate the row being left stale by an earlier recovery scan.
        $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_AUDIT_LOG . ' SET updated_at = ? WHERE id = ?',
            ['2020-01-01 00:00:00', $operation->operationId],
        );

        // A second, more accurate recovery classification of the SAME recovery_required row must be
        // written and must refresh updated_at, not be reported-yet-dropped.
        self::assertTrue($this->journal->complete($operation, OperationStatus::RecoveryRequired, 'more accurate diagnosis'));

        $row = $this->auditRow($operation->operationId);
        self::assertSame(OperationStatus::RecoveryRequired->value, $row['status']);
        self::assertSame('more accurate diagnosis', $row['error_message'], 'the newer recovery reason is persisted, not dropped');
        self::assertNotSame('2020-01-01 00:00:00', $row['updated_at'], 'updated_at is bumped so the row is not immediately re-scanned');
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
    public function concurrentDifferentCompletionCannotOverwriteTheWinningOutcome(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback($connection));
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(OperationStatus::InProgress->value, OperationStatus::Failed->value);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_ends_with($sql, 'WHERE id = ? AND status = ?')),
                self::callback(static fn (array $parameters): bool => array_slice($parameters, -2) === [81, OperationStatus::InProgress->value]),
            )
            ->willReturn(0);
        $deliveries = $this->createMock(OperationDeliveryStoreInterface::class);
        $deliveries->expects(self::never())->method('activateForOutcome');
        $journal = new OperationJournal($connection, new OperationObserverRegistry([]), $deliveries);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('completed concurrently with another outcome');
        $journal->complete(
            new OperationHandle(81, $this->intent()),
            OperationStatus::Completed,
        );
    }

    #[Test]
    public function concurrentIdenticalCompletionRemainsIdempotent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback($connection));
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(OperationStatus::InProgress->value, OperationStatus::Completed->value);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_ends_with($sql, 'WHERE id = ? AND status = ?')),
                self::callback(static fn (array $parameters): bool => array_slice($parameters, -2) === [82, OperationStatus::InProgress->value]),
            )
            ->willReturn(0);
        $deliveries = $this->createMock(OperationDeliveryStoreInterface::class);
        $deliveries->expects(self::once())
            ->method('activateForOutcome')
            ->with(82, OperationDeliveryOutcome::Success)
            ->willReturn(0);
        $journal = new OperationJournal($connection, new OperationObserverRegistry([]), $deliveries);

        self::assertTrue($journal->complete(
            new OperationHandle(82, $this->intent()),
            OperationStatus::Completed,
        ));
    }

    #[Test]
    public function deadObserverCanMarkACommittedOperationWithoutChangingItsOutcome(): void
    {
        $operation = $this->journal->begin($this->intent());
        $this->journal->complete($operation, OperationStatus::Completed);

        self::assertSame(ObserverAuditReconciliationStatus::Recorded, $this->journal->recordObserverFailure($operation->operationId, 'events'));
        $row = $this->auditRow($operation->operationId);
        self::assertSame(OperationStatus::CompletedWithObserverError->value, $row['status']);
        self::assertSame('Durable observer "events" did not complete.', $row['error_message']);
        self::assertSame(ObserverAuditReconciliationStatus::Recorded, $this->journal->recordObserverFailure($operation->operationId, 'rules'));
        self::assertSame('Durable observer "events" did not complete.; Durable observer "rules" did not complete.', $this->auditRow($operation->operationId)['error_message']);
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
        self::assertSame(ObserverAuditReconciliationStatus::Recorded, $this->journal->recordObserverFailure($operation->operationId, 'test'));
        $this->reconcileDeadDelivery($deliveryId);

        self::assertSame(1, $this->deliveries->requeueDead($this->deliveries->dead()));
        self::assertSame(ObserverAuditReconciliationStatus::Deferred, $this->journal->resolveObserverFailures($operation->operationId));
        self::assertSame(
            OperationStatus::CompletedWithObserverError->value,
            $this->auditRow($operation->operationId)['status'],
        );

        $retry = $this->deliveries->claim($deliveryId, 'worker-two', 300);
        self::assertNotNull($retry);
        self::assertTrue($this->deliveries->markDelivered($retry));
        self::assertSame(ObserverAuditReconciliationStatus::Recorded, $this->journal->resolveObserverFailures($operation->operationId));
        $row = $this->auditRow($operation->operationId);
        self::assertSame(OperationStatus::Completed->value, $row['status']);
        self::assertNull($row['error_message']);
        self::assertNull($row['durable_observer_failures']);
    }

    #[Test]
    public function resolvedDeliveryRestoresTheExactNonDurableWarning(): void
    {
        $operation = $this->journal->begin($this->intent());
        $warning = 'Primary observer failed; payload contained; separators.';
        $this->journal->complete($operation, OperationStatus::CompletedWithObserverError, $warning);
        $deliveryId = $this->deliveries->due()[0];
        $delivery = $this->deliveries->claim($deliveryId, 'worker-one', 300);
        self::assertNotNull($delivery);
        self::assertTrue($this->deliveries->markDead($delivery, 'unavailable'));
        self::assertSame(ObserverAuditReconciliationStatus::Recorded, $this->journal->recordObserverFailure($operation->operationId, 'test'));
        $this->reconcileDeadDelivery($deliveryId);

        self::assertSame(1, $this->deliveries->requeueDead($this->deliveries->dead()));
        $retry = $this->deliveries->claim($deliveryId, 'worker-two', 300);
        self::assertNotNull($retry);
        self::assertTrue($this->deliveries->markDelivered($retry));
        self::assertSame(ObserverAuditReconciliationStatus::Recorded, $this->journal->resolveObserverFailures($operation->operationId));
        $row = $this->auditRow($operation->operationId);
        self::assertSame(OperationStatus::CompletedWithObserverError->value, $row['status']);
        self::assertSame($warning, $row['error_message']);
        self::assertNull($row['durable_observer_failures']);
    }

    #[Test]
    public function observerFailureWaitsForACommittedOutcomeAndSkipsFailedOperations(): void
    {
        $operation = $this->journal->begin($this->intent());

        self::assertSame(
            ObserverAuditReconciliationStatus::Deferred,
            $this->journal->recordObserverFailure($operation->operationId, 'test'),
        );
        self::assertTrue($this->journal->complete($operation, OperationStatus::Failed, 'Mutation failed.'));
        self::assertSame(
            ObserverAuditReconciliationStatus::NotApplicable,
            $this->journal->recordObserverFailure($operation->operationId, 'test'),
        );
        self::assertSame(
            ObserverAuditReconciliationStatus::NotApplicable,
            $this->journal->recordObserverFailure(999_999, 'test'),
        );
        self::assertSame(
            ObserverAuditReconciliationStatus::NotApplicable,
            $this->journal->resolveObserverFailures(999_999),
        );
    }

    private function reconcileDeadDelivery(string $deliveryId): void
    {
        $audit = $this->deliveries->awaitingAudit($deliveryId);
        self::assertNotNull($audit);
        self::assertSame(OperationDeliveryStatus::Dead, $audit->status);
        self::assertTrue($this->deliveries->markAuditReconciled($audit));
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
        $table->addColumn('durable_observer_failures', 'text', ['notnull' => false]);
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
