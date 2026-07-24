<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationDeliveryStore::class)]
final class OperationDeliveryStoreTest extends TestCase
{
    private Connection $connection;
    private OperationDeliveryStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createTable($this->connection);
        $this->store = new OperationDeliveryStore($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function dueAndDeadRejectOutOfRangeLimits(): void
    {
        $rejections = 0;
        foreach ([0, 1_001] as $limit) {
            foreach (['due', 'dead'] as $method) {
                try {
                    $this->store->{$method}($limit);
                    self::fail(sprintf('%s accepted an out-of-range limit.', $method));
                } catch (\InvalidArgumentException) {
                    ++$rejections;
                }
            }
        }
        self::assertSame(4, $rejections);
    }

    #[Test]
    public function prepareIsIdempotentAndActivationSelectsOnlyTheActualOutcome(): void
    {
        $handle = $this->handle(41);
        $prepared = [
            new PreparedDelivery('observer', 'success:0', OperationDeliveryOutcome::Success, ['value' => 'original']),
            new PreparedDelivery('observer', 'failure:0', OperationDeliveryOutcome::Failure, ['reason' => 'failed']),
        ];

        $firstIds = $this->store->prepare($handle, $prepared);
        self::assertSame($firstIds, $this->store->prepare($handle, $prepared));
        self::assertCount(2, $firstIds);
        self::assertTrue($this->store->hasUnresolved(41));

        self::assertSame(1, $this->store->activateForOutcome(41, OperationDeliveryOutcome::Success));
        self::assertSame(0, $this->store->activateForOutcome(41, OperationDeliveryOutcome::Success));
        self::assertSame([$firstIds[0]], $this->store->due());
        self::assertSame(OperationDeliveryStatus::Pending->value, $this->row($firstIds[0])['status']);
        self::assertSame(OperationDeliveryStatus::Cancelled->value, $this->row($firstIds[1])['status']);

        $this->expectException(\LogicException::class);
        $this->store->activateForOutcome(41, OperationDeliveryOutcome::Failure);
    }

    #[Test]
    public function stableDeliveryIdRejectsAPayloadMismatch(): void
    {
        $handle = $this->handle(45);
        $this->store->prepare($handle, [
            new PreparedDelivery('observer', 'stable:0', OperationDeliveryOutcome::Success, ['value' => 'first']),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is already used by another payload');
        $this->store->prepare($handle, [
            new PreparedDelivery('observer', 'stable:0', OperationDeliveryOutcome::Success, ['value' => 'second']),
        ]);
    }

    #[Test]
    public function claimIsAtomicAcrossConnectionsAndOnlyTheClaimTokenCanCompleteIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-pilot-delivery-');
        self::assertIsString($path);
        $firstConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $secondConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);

        try {
            self::createTable($firstConnection);
            $firstStore = new OperationDeliveryStore($firstConnection);
            $secondStore = new OperationDeliveryStore($secondConnection);
            $id = $firstStore->prepare($this->handle(42), [
                new PreparedDelivery('observer', 'success:0', OperationDeliveryOutcome::Success),
            ])[0];
            $firstStore->activateForOutcome(42, OperationDeliveryOutcome::Success);

            $firstClaim = $firstStore->claim($id, 'worker-one', 300);
            self::assertNotNull($firstClaim);
            self::assertNull($secondStore->claim($id, 'worker-two', 300));
            self::assertFalse($secondStore->markDelivered(new \Oronts\AssetPilotBundle\Model\DeliveryEnvelope(
                $firstClaim->deliveryId,
                $firstClaim->operationId,
                $firstClaim->deliveryKey,
                $firstClaim->observerId,
                $firstClaim->outcome,
                $firstClaim->intent,
                $firstClaim->payload,
                $firstClaim->attempt,
                'worker-two',
            )));
            self::assertTrue($firstStore->markDelivered($firstClaim));
            self::assertFalse($firstStore->hasUnresolved(42));
        } finally {
            $firstConnection->close();
            $secondConnection->close();
            unlink($path);
        }
    }

    #[Test]
    public function expiredLeaseIsDueAndReclaimedWithTheSameStableId(): void
    {
        $id = $this->store->prepare($this->handle(43), [
            new PreparedDelivery('observer', 'success:0', OperationDeliveryOutcome::Success),
        ])[0];
        $this->store->activateForOutcome(43, OperationDeliveryOutcome::Success);

        $first = $this->store->claim($id, 'worker-one', 300);
        self::assertNotNull($first);
        $this->connection->update(Installer::TABLE_OPERATION_DELIVERY, ['locked_until' => '2000-01-01 00:00:00'], ['id' => $id]);

        self::assertSame([$id], $this->store->due());
        $second = $this->store->claim($id, 'worker-two', 300);
        self::assertNotNull($second);
        self::assertSame($first->deliveryId, $second->deliveryId);
        self::assertSame(2, $second->attempt);
        self::assertFalse($this->store->markDelivered($first));
        self::assertFalse($this->store->markRetry($first, 'stale retry', new \DateTimeImmutable('2000-01-01 00:00:00')));
        self::assertFalse($this->store->markDead($first, 'stale dead letter'));
        self::assertTrue($this->store->markDelivered($second));
    }

    #[Test]
    public function unchangedLeaseUpdateStillConfirmsFencedOwnership(): void
    {
        $id = $this->store->prepare($this->handle(49), [
            new PreparedDelivery('observer', 'success:0', OperationDeliveryOutcome::Success),
        ])[0];
        $this->store->activateForOutcome(49, OperationDeliveryOutcome::Success);
        $claim = $this->store->claim($id, 'worker-one', 300);
        self::assertNotNull($claim);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);
        $connection->expects(self::once())->method('fetchOne')->willReturn(1);

        self::assertTrue((new OperationDeliveryStore($connection))->renewLease($claim, 300));
    }

    #[Test]
    public function leaseRenewalIsClaimTokenFencedAndCannotReviveAnExpiredLease(): void
    {
        $id = $this->store->prepare($this->handle(46), [
            new PreparedDelivery('observer', 'success:0', OperationDeliveryOutcome::Success),
        ])[0];
        $this->store->activateForOutcome(46, OperationDeliveryOutcome::Success);
        $claim = $this->store->claim($id, 'worker-one', 300);
        self::assertNotNull($claim);
        $initialExpiry = (string) $this->row($id)['locked_until'];

        self::assertTrue($this->store->renewLease($claim, 600));
        self::assertGreaterThan($initialExpiry, (string) $this->row($id)['locked_until']);
        self::assertFalse($this->store->renewLease(new \Oronts\AssetPilotBundle\Model\DeliveryEnvelope(
            $claim->deliveryId,
            $claim->operationId,
            $claim->deliveryKey,
            $claim->observerId,
            $claim->outcome,
            $claim->intent,
            $claim->payload,
            $claim->attempt,
            'worker-two',
        ), 600));

        $this->connection->update(
            Installer::TABLE_OPERATION_DELIVERY,
            ['locked_until' => '2000-01-01 00:00:00'],
            ['id' => $id],
        );
        self::assertFalse($this->store->renewLease($claim, 600));
        self::assertNotNull($this->store->claim($id, 'worker-two', 300));
    }

    #[Test]
    public function exhaustedExpiredClaimIsDeadLetteredWithoutAnotherAttempt(): void
    {
        $id = $this->store->prepare($this->handle(47), [
            new PreparedDelivery('observer', 'success:0', OperationDeliveryOutcome::Success),
        ])[0];
        $this->store->activateForOutcome(47, OperationDeliveryOutcome::Success);
        $claim = $this->store->claim($id, 'crashed-worker', 300);
        self::assertNotNull($claim);
        $this->connection->update(
            Installer::TABLE_OPERATION_DELIVERY,
            ['locked_until' => '2000-01-01 00:00:00'],
            ['id' => $id],
        );

        $dead = $this->store->deadLetterExhausted($id, 1, 'Lease expired.');
        self::assertNotNull($dead);
        self::assertSame(47, $dead->operationId);
        self::assertSame(1, $dead->attempts);
        self::assertSame(OperationDeliveryStatus::Dead->value, $this->row($id)['status']);
        self::assertNull($this->store->deadLetterExhausted($id, 1, 'Lease expired.'));
        self::assertFalse($this->store->markDelivered($claim));
        self::assertSame([$id], $this->store->due());
        self::assertSame([], $this->store->dead());
        $audit = $this->store->awaitingAudit($id);
        self::assertNotNull($audit);
        self::assertSame(OperationDeliveryStatus::Dead, $audit->status);
        self::assertTrue($this->store->markAuditReconciled($audit));
        self::assertFalse($this->store->markAuditReconciled($audit));
        self::assertSame([], $this->store->due());
        self::assertSame([$id], array_map(static fn ($delivery): string => $delivery->deliveryId, $this->store->dead()));
    }

    #[Test]
    public function retryAndDeadAreTokenGuardedTerminalTransitions(): void
    {
        $id = $this->store->prepare($this->handle(44), [
            new PreparedDelivery('observer', 'success:0', OperationDeliveryOutcome::Success),
        ])[0];
        $this->store->activateForOutcome(44, OperationDeliveryOutcome::Success);
        $first = $this->store->claim($id, 'worker-one', 300);
        self::assertNotNull($first);
        self::assertTrue($this->store->markRetry($first, 'temporary', new \DateTimeImmutable('2000-01-01 00:00:00')));

        $second = $this->store->claim($id, 'worker-two', 300);
        self::assertNotNull($second);
        self::assertSame($id, $second->deliveryId);
        self::assertTrue($this->store->markDead($second, 'permanent'));
        self::assertFalse($this->store->hasUnresolved(44));
        self::assertSame(OperationDeliveryStatus::Dead->value, $this->row($id)['status']);
        self::assertSame('permanent', $this->row($id)['last_error']);
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function handle(int $operationId): OperationHandle
    {
        return new OperationHandle($operationId, new OperationIntent(
            OperationKind::Move,
            7,
            '/source/a.jpg',
            '/target/a.jpg',
            9,
            'Product',
            'images',
            TriggerType::Manual,
            ActorContext::user(12),
            ['rule' => ['actions' => [['type' => 'set_property', 'value' => 'original']]]],
        ));
    }

    public static function createTable(Connection $connection): void
    {
        $schema = new Schema();
        $table = $schema->createTable(Installer::TABLE_OPERATION_DELIVERY);
        $table->addColumn('id', 'string', ['length' => 64]);
        $table->addColumn('operation_id', 'integer');
        $table->addColumn('delivery_key', 'string', ['length' => 191]);
        $table->addColumn('observer_id', 'string', ['length' => 191]);
        $table->addColumn('outcome', 'string', ['length' => 16]);
        $table->addColumn('intent_payload', 'text');
        $table->addColumn('payload', 'text');
        $table->addColumn('status', 'string', ['length' => 20]);
        $table->addColumn('attempts', 'integer');
        $table->addColumn('available_at', 'datetime');
        $table->addColumn('lock_token', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('locked_until', 'datetime', ['notnull' => false]);
        $table->addColumn('last_error', 'text', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime');
        $table->addColumn('updated_at', 'datetime');
        $table->addColumn('delivered_at', 'datetime', ['notnull' => false]);
        $table->addColumn('audit_reconciled_at', 'datetime', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['operation_id', 'observer_id', 'delivery_key'], 'uniq_operation_delivery_key');
        $table->addIndex(['status', 'available_at'], 'idx_operation_delivery_due');
        $table->addIndex(['operation_id', 'status'], 'idx_operation_delivery_operation');
        $table->addIndex(['status', 'audit_reconciled_at'], 'idx_operation_delivery_audit_reconcile');
        $connection->createSchemaManager()->createTable($table);
    }
}
