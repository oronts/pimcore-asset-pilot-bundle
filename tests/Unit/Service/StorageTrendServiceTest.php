<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\StorageTrendService;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(StorageTrendService::class)]
class StorageTrendServiceTest extends TestCase
{
    #[Test]
    public function captureCommitsOneRunAndItsTypeRowsAtomically(): void
    {
        $connection = $this->connection();
        $result = $this->service($connection, [
            'totalCount' => 3,
            'totalSize' => 150,
            'byType' => [
                ['type' => 'image', 'count' => 2, 'total_size' => 100],
                ['type' => 'video', 'count' => 1, 'total_size' => 50],
            ],
        ])->capture();

        self::assertTrue($result['captured']);
        self::assertSame(2, $result['types']);
        self::assertSame('completed', $connection->fetchOne('SELECT status FROM asset_pilot_storage_run'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_pilot_storage_snapshot'));
    }

    #[Test]
    public function zeroUnusedAssetsStillProduceACompletedZeroRun(): void
    {
        $connection = $this->connection();
        $service = $this->service($connection, ['totalCount' => 0, 'totalSize' => 0, 'byType' => []]);

        $result = $service->capture();

        self::assertTrue($result['captured']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_pilot_storage_snapshot'));
        self::assertSame([
            'totalCount' => 0,
            'totalSize' => 0,
            'totalSizeFormatted' => '0 B',
            'unknownSizeCount' => 0,
            'byType' => [],
        ], $service->latestUnusedStats());
    }

    #[Test]
    public function freshRunSkipsAnotherExpensiveCapture(): void
    {
        $connection = $this->connection();
        $connection->insert('asset_pilot_storage_run', [
            'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'),
            'status' => 'completed',
            'total_count' => 0,
            'total_size' => 0,
            'error_message' => null,
        ]);
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('getUnusedStats');
        $service = new StorageTrendService($connection, $finder, new NullLogger(), $this->loopGuard(), minimumIntervalSeconds: 3600, retentionDays: 0);

        $result = $service->capture();

        self::assertFalse($result['captured']);
        self::assertSame('A fresh storage snapshot already exists.', $result['reason']);
    }

    #[Test]
    public function trendIncludesExplicitZeroRunsAndTypeGaps(): void
    {
        $connection = $this->connection();
        $first = $this->insertRun($connection, '2026-06-18 00:00:00', 3, 150);
        $second = $this->insertRun($connection, '2026-06-19 00:00:00', 0, 0);
        $connection->insert('asset_pilot_storage_snapshot', ['run_id' => $first, 'captured_at' => '2026-06-18 00:00:00', 'type' => 'image', 'unused_count' => 2, 'unused_size' => 100]);
        $service = $this->service($connection, []);

        self::assertSame([
            ['capturedAt' => '2026-06-19 00:00:00', 'count' => 0, 'size' => 0, 'unknownSizeCount' => 0],
            ['capturedAt' => '2026-06-18 00:00:00', 'count' => 3, 'size' => 150, 'unknownSizeCount' => 0],
        ], $service->trend());
        self::assertSame([
            ['capturedAt' => '2026-06-19 00:00:00', 'count' => 0, 'size' => 0, 'unknownSizeCount' => 0],
            ['capturedAt' => '2026-06-18 00:00:00', 'count' => 2, 'size' => 100, 'unknownSizeCount' => 0],
        ], $service->trend('image'));
        self::assertGreaterThan($first, $second);
    }

    #[Test]
    public function failedCaptureLeavesAFailedRunAndNoCompletedSnapshot(): void
    {
        $connection = $this->connection();
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('getUnusedStats')->willThrowException(new \RuntimeException('storage unavailable'));
        $service = new StorageTrendService($connection, $finder, new NullLogger(), $this->loopGuard(), minimumIntervalSeconds: 0, retentionDays: 0);

        try {
            $service->capture();
            self::fail('Expected capture failure.');
        } catch (\RuntimeException) {
        }

        self::assertSame('failed', $connection->fetchOne('SELECT status FROM asset_pilot_storage_run'));
        self::assertNull($service->latestUnusedStats());
    }

    #[Test]
    public function retentionFailureDoesNotInvalidateTheCommittedCapture(): void
    {
        $connection = $this->connection();
        $oldRun = $this->insertRun($connection, '2020-01-01 00:00:00', 1, 10);
        $connection->insert('asset_pilot_storage_snapshot', ['run_id' => $oldRun, 'captured_at' => '2020-01-01 00:00:00', 'type' => 'image', 'unused_count' => 1, 'unused_size' => 10]);
        $connection->executeStatement("CREATE TRIGGER prevent_snapshot_delete BEFORE DELETE ON asset_pilot_storage_snapshot BEGIN SELECT RAISE(ABORT, 'retention unavailable'); END");
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('getUnusedStats')->willReturn(['totalCount' => 0, 'totalSize' => 0, 'byType' => []]);
        $service = new StorageTrendService($connection, $finder, new NullLogger(), $this->loopGuard(), minimumIntervalSeconds: 0, retentionDays: 1);

        $result = $service->capture();

        self::assertTrue($result['captured']);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM asset_pilot_storage_run WHERE status = 'completed' AND id <> ?", [$oldRun]));
    }

    #[Test]
    public function capturePersistsUnknownSizeCounts(): void
    {
        $connection = $this->connection();
        $service = $this->service($connection, [
            'totalCount' => 2,
            'totalSize' => 0,
            'unknownSizeCount' => 1,
            'byType' => [['type' => 'image', 'count' => 2, 'total_size' => 0, 'unknown_size_count' => 1]],
        ]);

        $result = $service->capture();

        self::assertSame(1, $result['unknownSizeCount']);
        self::assertSame(1, $service->latestUnusedStats()['unknownSizeCount']);
        self::assertSame(1, $service->trend()[0]['unknownSizeCount']);
    }

    private function service(Connection $connection, array $stats): StorageTrendService
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('getUnusedStats')->willReturn($stats);

        return new StorageTrendService($connection, $finder, new NullLogger(), $this->loopGuard(), minimumIntervalSeconds: 0, retentionDays: 0);
    }

    private function loopGuard(): LoopGuard
    {
        return new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE asset_pilot_storage_run (id INTEGER PRIMARY KEY AUTOINCREMENT, started_at TEXT NOT NULL, completed_at TEXT, status TEXT NOT NULL, total_count INTEGER NOT NULL, total_size INTEGER NOT NULL, unknown_size_count INTEGER NOT NULL DEFAULT 0, error_message TEXT)');
        $connection->executeStatement('CREATE TABLE asset_pilot_storage_snapshot (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, captured_at TEXT NOT NULL, type TEXT NOT NULL, unused_count INTEGER NOT NULL, unused_size INTEGER NOT NULL, unknown_size_count INTEGER NOT NULL DEFAULT 0, UNIQUE(run_id, type))');

        return $connection;
    }

    private function insertRun(Connection $connection, string $at, int $count, int $size): int
    {
        $connection->insert('asset_pilot_storage_run', [
            'started_at' => $at,
            'completed_at' => $at,
            'status' => 'completed',
            'total_count' => $count,
            'total_size' => $size,
            'unknown_size_count' => 0,
            'error_message' => null,
        ]);

        return (int) $connection->lastInsertId();
    }
}
