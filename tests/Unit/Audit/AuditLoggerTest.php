<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Audit;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(AuditLogger::class)]
class AuditLoggerTest extends TestCase
{
    #[Test]
    public function persistsTheActingUserId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::anything(), self::callback(static fn (array $data): bool => ($data['user_id'] ?? 'missing') === 42));

        (new AuditLogger($connection, new NullLogger()))->log($this->operation(42));
    }

    #[Test]
    public function persistsNullUserIdForAutomatedMoves(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::anything(), self::callback(static fn (array $data): bool => array_key_exists('user_id', $data) && $data['user_id'] === null));

        (new AuditLogger($connection, new NullLogger()))->log($this->operation(null));
    }

    #[Test]
    public function doesNotWriteWhenDisabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        (new AuditLogger($connection, new NullLogger(), enabled: false))->log($this->operation(42));
    }

    #[Test]
    public function getStatsIsServedFromCacheWithinTtl(): void
    {
        $logger = new class ($this->createMock(Connection::class), new NullLogger(), true, 90, new StatsCache(new ArrayAdapter()), 60) extends AuditLogger {
            public int $computeCalls = 0;

            protected function computeStats(): array
            {
                ++$this->computeCalls;

                return ['completed' => 1, 'by_class' => []];
            }
        };

        $logger->getStats();
        $logger->getStats();

        self::assertSame(1, $logger->computeCalls, 'the second getStats() is served from cache');
    }

    #[Test]
    public function getStatsAlwaysComputesWhenTtlIsZero(): void
    {
        $logger = new class ($this->createMock(Connection::class), new NullLogger(), true, 90, new StatsCache(new ArrayAdapter()), 0) extends AuditLogger {
            public int $computeCalls = 0;

            protected function computeStats(): array
            {
                ++$this->computeCalls;

                return ['by_class' => []];
            }
        };

        $logger->getStats();
        $logger->getStats();

        self::assertSame(2, $logger->computeCalls, 'ttl 0 disables the cache');
    }

    #[Test]
    public function iterateForExportYieldsAllRowsAcrossPagesThenStops(): void
    {
        $logger = new class ($this->createMock(Connection::class), new NullLogger()) extends AuditLogger {
            public int $pageCalls = 0;

            protected function fetchExportPage(array $filters, int $chunkSize, ?array $cursor): array
            {
                ++$this->pageCalls;

                return match ($this->pageCalls) {
                    1 => [['id' => 3, 'created_at' => '2026-06-19 03:00:00'], ['id' => 2, 'created_at' => '2026-06-19 02:00:00']],
                    2 => [['id' => 1, 'created_at' => '2026-06-19 01:00:00']],
                    default => [],
                };
            }
        };

        $rows = iterator_to_array($logger->iterateForExport([], 2), false);

        self::assertSame([3, 2, 1], array_column($rows, 'id'));
        self::assertSame(2, $logger->pageCalls, 'a short final page stops the cursor without an extra query');
    }

    #[Test]
    public function exportPageQueryUsesAKeysetCursorNotAnOffset(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'dbname' => 'x', 'user' => 'x', 'password' => 'x', 'serverVersion' => '8.0.0',
        ]);
        $logger = new AuditLogger($connection, new NullLogger());

        $method = new \ReflectionMethod(AuditLogger::class, 'exportPageQuery');
        /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
        $qb = $method->invoke($logger, ['status' => 'failed'], 1000, ['created_at' => '2026-06-19 00:00:00', 'id' => 5]);
        $sql = $qb->getSQL();

        self::assertStringContainsString('created_at < :cursorAt', $sql);
        self::assertStringContainsString('id < :cursorId', $sql);
        self::assertStringContainsString('status = :status', $sql);
        self::assertStringContainsStringIgnoringCase('ORDER BY created_at DESC', $sql);
        self::assertSame(1000, $qb->getMaxResults());
    }

    #[Test]
    public function classBreakdownKeepsActionFailedOutOfTheMoveTotal(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE asset_pilot_audit_log (object_class VARCHAR(255), status VARCHAR(50), rule_name VARCHAR(255))');
        $seed = static function (string $class, string $status, int $n) use ($connection): void {
            for ($i = 0; $i < $n; ++$i) {
                $connection->executeStatement('INSERT INTO asset_pilot_audit_log (object_class, status, rule_name) VALUES (?, ?, ?)', [$class, $status, 'r1']);
            }
        };
        $seed('Product', 'completed', 10);
        $seed('Product', 'failed', 2);
        $seed('Product', 'skipped', 8);
        $seed('Product', 'action_failed', 5);

        $logger = new AuditLogger($connection, new NullLogger(), true, 90, new StatsCache(new ArrayAdapter()), 60);
        $breakdown = $logger->getClassBreakdown();

        self::assertCount(1, $breakdown);
        $product = $breakdown[0];
        self::assertSame(10, $product['completed']);
        self::assertSame(2, $product['failed']);
        self::assertSame(8, $product['skipped']);
        // total is a move-total: completed+failed+skipped (20), NOT inflated to 25 by the 5 action_failed rows.
        self::assertSame(20, $product['total']);
        self::assertSame($product['completed'] + $product['failed'] + $product['skipped'], $product['total']);
        self::assertArrayNotHasKey('action_failed', $product);
    }

    private function operation(?int $userId): MoveOperation
    {
        return new MoveOperation(
            assetId: 1,
            sourcePath: '/source/file.jpg',
            targetPath: '/target/file.jpg',
            objectId: 2,
            objectClass: 'Product',
            ruleName: 'revert:images',
            status: OperationStatus::Completed,
            triggerType: TriggerType::Manual,
            userId: $userId,
        );
    }
}
