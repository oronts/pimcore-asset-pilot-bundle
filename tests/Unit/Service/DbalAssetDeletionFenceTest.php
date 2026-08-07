<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\DependencyProjectionSchema;
use Oronts\AssetPilotBundle\Exception\AssetDeletionFenceLostException;
use Oronts\AssetPilotBundle\Service\DbalAssetDeletionFence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Element\ValidationException;

#[CoversClass(DbalAssetDeletionFence::class)]
class DbalAssetDeletionFenceTest extends TestCase
{
    #[Test]
    public function acquireReturnsA64CharTokenAndRejectsASecondClaimOnTheSameAsset(): void
    {
        $fence = new DbalAssetDeletionFence($this->connection());

        $token = $fence->acquire(42, 'unused_delete');

        self::assertNotNull($token);
        self::assertSame(64, \strlen($token));
        self::assertNull($fence->acquire(42, 'quarantine_purge'), 'a second claim on the same asset is busy');
    }

    #[Test]
    public function acquireFailsClosedInsideAnAmbientTransaction(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        $this->expectException(\LogicException::class);
        (new DbalAssetDeletionFence($connection))->acquire(42, 'unused_delete');
    }

    #[Test]
    public function acquireFailsClosedOnANonAutocommitConnection(): void
    {
        $connection = $this->connection();
        $connection->setAutoCommit(false);

        $this->expectException(\LogicException::class);
        (new DbalAssetDeletionFence($connection))->acquire(42, 'unused_delete');
    }

    #[Test]
    public function refreshOrFailExtendsTheOwnersLeaseButRejectsAForeignToken(): void
    {
        $fence = new DbalAssetDeletionFence($this->connection());
        $token = $fence->acquire(42, 'unused_delete');
        self::assertNotNull($token);

        $fence->refreshOrFail(42, $token);

        $this->expectException(AssetDeletionFenceLostException::class);
        $fence->refreshOrFail(42, 'not-the-owner');
    }

    #[Test]
    public function refreshOrFailThrowsOnceTheRowHasBeenReleased(): void
    {
        $fence = new DbalAssetDeletionFence($this->connection());
        $token = $fence->acquire(42, 'unused_delete');
        self::assertNotNull($token);
        $fence->release(42, $token);

        $this->expectException(AssetDeletionFenceLostException::class);
        $fence->refreshOrFail(42, $token);
    }

    #[Test]
    public function refreshOrFailToleratesASameSecondNoOpRefresh(): void
    {
        $fence = new class ($this->connection()) extends DbalAssetDeletionFence {
            public string $clock = '2026-07-17 12:00:00';

            protected function now(): string
            {
                return $this->clock;
            }
        };
        $token = $fence->acquire(42, 'unused_delete');
        self::assertNotNull($token);

        $fence->refreshOrFail(42, $token);
        $fence->refreshOrFail(42, $token);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function releaseOnlyRemovesTheOwnersRow(): void
    {
        $fence = new DbalAssetDeletionFence($this->connection());
        $token = $fence->acquire(42, 'unused_delete');
        self::assertNotNull($token);

        $fence->release(42, 'someone-else');
        self::assertNull($fence->acquire(42, 'x'), 'still fenced after a foreign-token release');

        $fence->release(42, $token);
        self::assertNotNull($fence->acquire(42, 'x'), 're-claimable once the owner releases');
    }

    #[Test]
    public function assertWritableTargetsPassesForExistingUnfencedAssets(): void
    {
        $fence = new DbalAssetDeletionFence($this->connectionWithAssets([1, 2, 3]));

        $fence->assertWritableTargets([1, 2, 3]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertWritableTargetsIgnoresAnEmptyTargetSet(): void
    {
        (new DbalAssetDeletionFence($this->connection()))->assertWritableTargets([]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertWritableTargetsRejectsAMissingAsset(): void
    {
        $fence = new DbalAssetDeletionFence($this->connectionWithAssets([1, 2]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('referenced asset 3 no longer exists');
        $fence->assertWritableTargets([1, 3]);
    }

    #[Test]
    public function assertWritableTargetsRejectsAFencedAsset(): void
    {
        $connection = $this->connectionWithAssets([1, 2]);
        $fence = new DbalAssetDeletionFence($connection);
        $fence->acquire(2, 'unused_delete');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('referenced asset 2 is being deleted');
        $fence->assertWritableTargets([1, 2]);
    }

    #[Test]
    public function expiredFenceCandidatesReturnsExpiredAndOrphanedRowsOldestFirst(): void
    {
        $connection = $this->connectionWithAssets([1, 2]);
        $fence = new class ($connection) extends DbalAssetDeletionFence {
            public string $clock = '2026-07-17 12:00:00';

            protected function now(): string
            {
                return $this->clock;
            }
        };

        $fence->clock = '2026-07-17 11:00:00';
        $fence->acquire(2, 'unused_delete');
        $fence->clock = '2026-07-17 11:59:50';
        $fence->acquire(3, 'quarantine_purge');
        $fence->clock = '2026-07-17 12:00:00';
        $fence->acquire(1, 'duplicate_delete');

        self::assertSame([2, 3], $fence->expiredFenceCandidates(10));
    }

    #[Test]
    public function reapAssetRemovesAnExpiredRowButSparesAConcurrentlyRefreshedOwner(): void
    {
        $connection = $this->connectionWithAssets([1]);
        $fence = new class ($connection) extends DbalAssetDeletionFence {
            public string $clock = '2026-07-17 12:00:00';

            protected function now(): string
            {
                return $this->clock;
            }
        };

        $fence->clock = '2026-07-17 11:00:00';
        $token = $fence->acquire(1, 'unused_delete');
        self::assertNotNull($token);

        $fence->clock = '2026-07-17 12:00:00';
        $fence->refreshOrFail(1, $token);
        self::assertFalse($fence->reapAsset(1), 'a just-refreshed fence is not reaped');

        $fence->clock = '2026-07-17 12:20:00';
        self::assertTrue($fence->reapAsset(1), 'an expired fence is reaped');
        self::assertNotNull($fence->acquire(1, 'x'), 're-claimable once reaped');
    }

    #[Test]
    public function reapAssetRemovesAnOrphanedRowRegardlessOfExpiry(): void
    {
        $fence = new DbalAssetDeletionFence($this->connectionWithAssets([]));

        self::assertNotNull($fence->acquire(9, 'quarantine_purge'));
        self::assertTrue($fence->reapAsset(9), 'a fence whose asset is gone is reaped even before expiry');
    }

    #[Test]
    public function reapAssetIsANoOpForAnAbsentRow(): void
    {
        self::assertFalse((new DbalAssetDeletionFence($this->connectionWithAssets([1])))->reapAsset(1));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        DependencyProjectionSchema::ensure($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }

    /** @param list<int> $assetIds */
    private function connectionWithAssets(array $assetIds): Connection
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT)');
        foreach ($assetIds as $id) {
            $connection->insert('assets', ['id' => $id, 'path' => '/x/', 'filename' => 'f' . $id, 'type' => 'image']);
        }

        return $connection;
    }
}
