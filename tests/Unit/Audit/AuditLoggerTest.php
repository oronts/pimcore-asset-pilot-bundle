<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

        (new AuditLogger($connection, new NullLogger(), $this->systemAuthorization()))->log($this->operation(42));
    }

    #[Test]
    public function persistsNullUserIdForAutomatedMoves(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::anything(), self::callback(static fn (array $data): bool => array_key_exists('user_id', $data) && $data['user_id'] === null));

        (new AuditLogger($connection, new NullLogger(), $this->systemAuthorization()))->log($this->operation(null));
    }

    #[Test]
    public function logReportsPersistenceFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('database unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new AuditLogger($connection, $logger, $this->systemAuthorization()))->log($this->operation(42));
    }

    #[Test]
    public function cleanupPreservesRecoverableOperationsAndUnresolvedDeliveries(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ' . Installer::TABLE_AUDIT_LOG . ' (id INTEGER PRIMARY KEY, status TEXT NOT NULL, created_at TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE ' . Installer::TABLE_OPERATION_DELIVERY . ' (id TEXT PRIMARY KEY, operation_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $old = (new \DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s');
        $recent = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        foreach ([
            [1, OperationStatus::Completed->value, $old],
            [2, OperationStatus::InProgress->value, $old],
            [3, OperationStatus::RecoveryRequired->value, $old],
            [4, OperationStatus::Completed->value, $old],
            [5, OperationStatus::Completed->value, $old],
            [6, OperationStatus::Completed->value, $recent],
        ] as [$id, $status, $createdAt]) {
            $connection->insert(Installer::TABLE_AUDIT_LOG, ['id' => $id, 'status' => $status, 'created_at' => $createdAt]);
        }
        $connection->insert(Installer::TABLE_OPERATION_DELIVERY, ['id' => 'pending', 'operation_id' => 4, 'status' => 'pending']);
        $connection->insert(Installer::TABLE_OPERATION_DELIVERY, ['id' => 'delivered', 'operation_id' => 5, 'status' => 'delivered']);

        $deleted = (new AuditLogger($connection, new NullLogger(), $this->systemAuthorization()))->cleanup(90);

        self::assertSame(2, $deleted);
        self::assertSame([2, 3, 4, 6], array_map('intval', $connection->fetchFirstColumn('SELECT id FROM ' . Installer::TABLE_AUDIT_LOG . ' ORDER BY id')));
        self::assertSame(['pending'], $connection->fetchFirstColumn('SELECT id FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' ORDER BY id'));
        $connection->close();
    }

    #[Test]
    public function getStatsIsServedFromCacheWithinTtl(): void
    {
        $logger = new class ($this->createMock(Connection::class), new NullLogger(), $this->systemAuthorization(), 90, new StatsCache(new ArrayAdapter()), 60) extends AuditLogger {
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
        $logger = new class ($this->createMock(Connection::class), new NullLogger(), $this->systemAuthorization(), 90, new StatsCache(new ArrayAdapter()), 0) extends AuditLogger {
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
        $logger = new class ($this->createMock(Connection::class), new NullLogger(), $this->systemAuthorization()) extends AuditLogger {
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
        $logger = new AuditLogger($connection, new NullLogger(), $this->systemAuthorization());

        $method = new \ReflectionMethod(AuditLogger::class, 'exportPageQuery');
        /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
        $qb = $method->invoke($logger, ['status' => 'failed'], 1000, ['created_at' => '2026-06-19 00:00:00', 'id' => 5]);
        $sql = $qb->getSQL();

        self::assertStringContainsString('created_at < :cursorAt', $sql);
        self::assertStringContainsString('id < :cursorId', $sql);
        self::assertStringContainsString('status = :status', $sql);
        self::assertStringContainsStringIgnoringCase('ORDER BY audit.created_at DESC, audit.id DESC', $sql);
        self::assertSame(1000, $qb->getMaxResults());
    }

    #[Test]
    public function classBreakdownIncludesOnlyTerminalMoveStatuses(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE asset_pilot_audit_log (object_class VARCHAR(255), status VARCHAR(50), rule_name VARCHAR(255))');
        $seed = static function (string $class, string $status, int $n) use ($connection): void {
            for ($i = 0; $i < $n; ++$i) {
                $connection->executeStatement('INSERT INTO asset_pilot_audit_log (object_class, status, rule_name) VALUES (?, ?, ?)', [$class, $status, 'r1']);
            }
        };
        $seed('Product', 'completed', 10);
        $seed('Product', 'completed_with_observer_error', 3);
        $seed('Product', 'failed', 2);
        $seed('Product', 'skipped', 8);
        $seed('Product', 'retired_status', 5);
        $seed('Product', 'pending', 4);
        $seed('Product', 'in_progress', 2);
        $seed('Product', 'recovery_required', 1);

        $logger = new AuditLogger($connection, new NullLogger(), $this->systemAuthorization(), 90, new StatsCache(new ArrayAdapter()), 60);
        $breakdown = $logger->getClassBreakdown();

        self::assertCount(1, $breakdown);
        $product = $breakdown[0];
        self::assertSame(13, $product['completed']);
        self::assertSame(3, $product['completed_with_observer_error']);
        self::assertSame(2, $product['failed']);
        self::assertSame(8, $product['skipped']);
        self::assertSame(23, $product['total']);
        self::assertSame($product['completed'] + $product['failed'] + $product['skipped'], $product['total']);
        self::assertArrayNotHasKey('retired_status', $product);
        self::assertArrayNotHasKey('pending', $product);
        self::assertArrayNotHasKey('in_progress', $product);
        self::assertArrayNotHasKey('recovery_required', $product);
    }

    #[Test]
    public function equalSortValuesUseTheIdAsADeterministicTieBreak(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createAuditTable($connection);
        foreach ([
            [1, 10, '/public/a.jpg', '/public/a.jpg', 100, 'Product', 'completed', 'images', '2026-07-14 03:00:00', 10],
            [2, 20, '/public/b.jpg', '/public/b.jpg', 200, 'Product', 'completed', 'images', '2026-07-14 03:00:00', 20],
            [3, 30, '/public/c.jpg', '/public/c.jpg', 300, 'Product', 'completed', 'images', '2026-07-14 03:00:00', 30],
        ] as $row) {
            $this->insertAuditRow($connection, $row);
        }

        $logger = new AuditLogger($connection, new NullLogger(), $this->systemAuthorization());

        self::assertSame([3, 2], array_map('intval', array_column($logger->getPaginated(1, 2)['items'], 'id')));
        self::assertSame([1], array_map('intval', array_column($logger->getPaginated(2, 2)['items'], 'id')));
        self::assertSame([1, 2], array_map('intval', array_column($logger->getPaginated(1, 2, sort: 'status', order: 'ASC')['items'], 'id')));
    }

    #[Test]
    public function workspaceFilteringIsBoundedAndRetainsDeletedHistory(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createAuditTable($connection);
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, admin INTEGER, active INTEGER, roles TEXT)');
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT)');
        $connection->executeStatement('CREATE TABLE objects (id INTEGER PRIMARY KEY, path TEXT, key TEXT)');
        $connection->executeStatement('CREATE TABLE users_workspaces_asset (userId INTEGER, cpath TEXT, view INTEGER)');
        $connection->executeStatement('CREATE TABLE users_workspaces_object (userId INTEGER, cpath TEXT, view INTEGER)');
        $connection->insert('users', ['id' => 7, 'admin' => 0, 'active' => 1, 'roles' => '8']);
        $connection->insert('users_workspaces_asset', ['userId' => 8, 'cpath' => '/public', 'view' => 1]);
        $connection->insert('users_workspaces_asset', ['userId' => 8, 'cpath' => '/secret', 'view' => 1]);
        $connection->insert('users_workspaces_asset', ['userId' => 7, 'cpath' => '/secret', 'view' => 0]);
        $connection->insert('users_workspaces_object', ['userId' => 8, 'cpath' => '/objects/public', 'view' => 1]);
        foreach ([
            ['id' => 10, 'path' => '/public/', 'filename' => 'live.jpg'],
            ['id' => 20, 'path' => '/secret/', 'filename' => 'hidden.jpg'],
            ['id' => 30, 'path' => '/public/', 'filename' => 'hidden-object.jpg'],
            ['id' => 40, 'path' => '/public/', 'filename' => 'deleted-object.jpg'],
        ] as $asset) {
            $connection->insert('assets', $asset);
        }
        foreach ([
            ['id' => 100, 'path' => '/objects/public/', 'key' => 'visible'],
            ['id' => 200, 'path' => '/objects/public/', 'key' => 'hidden-asset'],
            ['id' => 300, 'path' => '/objects/secret/', 'key' => 'hidden'],
        ] as $object) {
            $connection->insert('objects', $object);
        }
        foreach ([
            [1, 10, '/public/live.jpg', '/public/live.jpg', 100, 'Product', 'completed', 'images', '2026-07-14 03:00:00', 10],
            [2, 20, '/secret/hidden.jpg', '/secret/hidden.jpg', 200, 'Secret', 'failed', 'secret', '2026-07-14 03:00:00', 50],
            [3, 30, '/public/hidden-object.jpg', '/public/hidden-object.jpg', 300, 'SecretObject', 'failed', 'secret-object', '2026-07-14 03:00:00', 50],
            [4, 40, '/public/deleted-object.jpg', '/public/deleted-object.jpg', 400, 'Product', 'completed_with_observer_error', 'images', '2026-07-14 03:00:00', 20],
            [5, 50, '/public/deleted.jpg', '/public/deleted.jpg', 500, 'Product', 'skipped', 'images', '2026-07-14 03:00:00', null],
            [6, 60, '/secret/deleted.jpg', '/secret/deleted.jpg', 600, 'Secret', 'failed', 'secret', '2026-07-14 03:00:00', 50],
        ] as $row) {
            $this->insertAuditRow($connection, $row);
        }

        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $authorization->method('hasGlobalPermission')->willReturn(true);
        $logger = new AuditLogger($connection, new NullLogger(), $authorization, 90);

        $page = $logger->getPaginated(1, 2);

        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['pages']);
        self::assertSame([5, 4], array_map('intval', array_column($page['items'], 'id')));
        self::assertSame([5, 4], array_map('intval', array_column($logger->getRecent(2), 'id')));
        self::assertNotNull($logger->findById(5));
        self::assertNull($logger->findById(6));
        self::assertSame([
            'by_class' => ['Product' => 3],
            'completed' => 1,
            'completed_with_observer_error' => 1,
            'skipped' => 1,
        ], $logger->getStats());
        self::assertSame(['count' => 2, 'avgMs' => 15.0, 'minMs' => 10, 'maxMs' => 20], $logger->getDurationStats());

        $breakdown = $logger->getClassBreakdown()[0];
        self::assertSame(3, $breakdown['total']);
        self::assertSame(2, $breakdown['completed']);
        self::assertSame(1, $breakdown['completed_with_observer_error']);
    }

    #[Test]
    public function failedObjectSelectionCombinesObjectIdsWithAuditFilters(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createAuditTable($connection);
        $connection->executeStatement('CREATE TABLE objects (id INTEGER PRIMARY KEY)');
        foreach ([100, 200, 300] as $objectId) {
            $connection->insert('objects', ['id' => $objectId]);
        }
        $this->insertAuditRow($connection, [1, 10, '/a', '/b', 100, 'Product', 'failed', 'images', '2026-07-14 10:00:00', 10]);
        $this->insertAuditRow($connection, [2, 20, '/a', '/b', 200, 'Product', 'failed', 'images', '2026-07-15 11:00:00', 10]);
        $this->insertAuditRow($connection, [3, 30, '/a', '/b', 300, 'Product', 'failed', 'other', '2026-07-15 12:00:00', 10]);
        $logger = new AuditLogger($connection, new NullLogger(), $this->systemAuthorization());

        $rows = $logger->getDistinctFailedObjects([
            'object_ids' => [100, 200],
            'since' => '2026-07-15 00:00:00',
            'rule_name' => 'images',
            'object_class' => 'Product',
        ]);

        self::assertSame([200], array_column($rows, 'object_id'));
    }

    private function createAuditTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE asset_pilot_audit_log (id INTEGER PRIMARY KEY, asset_id INTEGER, asset_path_from TEXT, asset_path_to TEXT, object_id INTEGER, object_class TEXT, status TEXT, rule_name TEXT, created_at TEXT, duration_ms INTEGER)');
    }

    private function systemAuthorization(): ElementAuthorization
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::system());

        return $authorization;
    }

    /** @param array{int, int, string, string, int, string, string, string, string, int|null} $row */
    private function insertAuditRow(Connection $connection, array $row): void
    {
        $connection->insert('asset_pilot_audit_log', array_combine(
            ['id', 'asset_id', 'asset_path_from', 'asset_path_to', 'object_id', 'object_class', 'status', 'rule_name', 'created_at', 'duration_ms'],
            $row,
        ));
    }

    private function operation(?int $userId, OperationStatus $status = OperationStatus::Completed): MoveOperation
    {
        return new MoveOperation(
            assetId: 1,
            sourcePath: '/source/file.jpg',
            targetPath: '/target/file.jpg',
            objectId: 2,
            objectClass: 'Product',
            ruleName: 'revert:images',
            status: $status,
            triggerType: TriggerType::Manual,
            userId: $userId,
        );
    }
}
