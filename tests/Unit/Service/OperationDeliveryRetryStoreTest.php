<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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
final class OperationDeliveryRetryStoreTest extends TestCase
{
    private Connection $connection;
    private OperationDeliveryStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        OperationDeliveryStoreTest::createTable($this->connection);
        $this->store = new OperationDeliveryStore($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function exactReviewedDeadDeliveryIsRequeuedWithFreshRetryState(): void
    {
        $deliveryId = $this->deadDelivery(71, 'observer:first');
        $reviewed = $this->store->dead(1);

        self::assertCount(1, $reviewed);
        self::assertSame($deliveryId, $reviewed[0]->deliveryId);
        self::assertSame(1, $reviewed[0]->attempts);
        self::assertSame('permanent failure', $reviewed[0]->lastError);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $reviewed[0]->fingerprint);
        self::assertTrue($this->store->hasDead(71));

        self::assertSame(1, $this->store->requeueDead($reviewed));
        $row = $this->row($deliveryId);
        self::assertSame(OperationDeliveryStatus::Pending->value, $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['lock_token']);
        self::assertNull($row['locked_until']);
        self::assertNull($row['last_error']);
        self::assertNull($row['delivered_at']);
        self::assertFalse($this->store->hasDead(71));
        self::assertTrue($this->store->hasUnresolved(71));
        self::assertSame([$deliveryId], $this->store->due());
    }

    #[Test]
    public function changedReviewedRowRejectsTheWholeRetryBatch(): void
    {
        $firstId = $this->deadDelivery(72, 'observer:first');
        $secondId = $this->deadDelivery(73, 'observer:second');
        $reviewed = $this->store->dead(10);
        self::assertCount(2, $reviewed);

        $this->connection->update(Installer::TABLE_OPERATION_DELIVERY, ['last_error' => 'changed'], ['id' => $secondId]);

        try {
            $this->store->requeueDead($reviewed);
            self::fail('A changed reviewed row must invalidate the retry batch.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('changed after it was reviewed', $e->getMessage());
        }

        self::assertSame(OperationDeliveryStatus::Dead->value, $this->row($firstId)['status']);
        self::assertSame(OperationDeliveryStatus::Dead->value, $this->row($secondId)['status']);
    }

    private function deadDelivery(int $operationId, string $deliveryKey): string
    {
        $deliveryId = $this->store->prepare($this->handle($operationId), [
            new PreparedDelivery('observer', $deliveryKey, OperationDeliveryOutcome::Success),
        ])[0];
        $this->store->activateForOutcome($operationId, OperationDeliveryOutcome::Success);
        $delivery = $this->store->claim($deliveryId, 'worker', 300);
        self::assertNotNull($delivery);
        self::assertTrue($this->store->markDead($delivery, 'permanent failure'));

        return $deliveryId;
    }

    /** @return array<string, mixed> */
    private function row(string $deliveryId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ?',
            [$deliveryId],
        );
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
            ActorContext::system(),
        ));
    }
}
