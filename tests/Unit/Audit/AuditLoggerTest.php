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
