<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Service\StorageTrendService;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StorageTrendService::class)]
class StorageTrendServiceTest extends TestCase
{
    /**
     * @param array<string, mixed>                                  $stats          getUnusedStats() result
     * @param \ArrayObject<int, array{type: string, count: int, size: int}> $inserts
     * @param list<array{captured_at: string, unused_count: int, unused_size: int}> $trendRows
     */
    private function service(array $stats, \ArrayObject $inserts, array $trendRows = []): StorageTrendService
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('getUnusedStats')->willReturn($stats);

        return new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), $finder, $inserts, $trendRows) extends StorageTrendService {
            /**
             * @param \ArrayObject<int, array{type: string, count: int, size: int}> $inserts
             * @param list<array{captured_at: string, unused_count: int, unused_size: int}> $trendRows
             */
            public function __construct(Connection $c, UnusedAssetFinderInterface $f, private readonly \ArrayObject $inserts, private readonly array $trendRows)
            {
                parent::__construct($c, $f);
            }

            protected function insertSnapshot(string $capturedAt, string $type, int $count, int $size): void
            {
                $this->inserts->append(['type' => $type, 'count' => $count, 'size' => $size]);
            }

            protected function fetchTrendRows(?string $type, int $limit): array
            {
                return $this->trendRows;
            }
        };
    }

    #[Test]
    public function captureWritesOneSnapshotRowPerType(): void
    {
        $inserts = new \ArrayObject();
        $result = $this->service([
            'totalCount' => 3,
            'totalSize' => 150,
            'byType' => [
                ['type' => 'image', 'count' => 2, 'total_size' => 100],
                ['type' => 'video', 'count' => 1, 'total_size' => 50],
            ],
        ], $inserts)->capture();

        self::assertSame(2, $result['types']);
        self::assertSame(3, $result['totalCount']);
        self::assertSame(150, $result['totalSize']);
        self::assertSame(
            [['type' => 'image', 'count' => 2, 'size' => 100], ['type' => 'video', 'count' => 1, 'size' => 50]],
            $inserts->getArrayCopy(),
        );
    }

    #[Test]
    public function captureWritesNothingWhenNothingIsUnused(): void
    {
        $inserts = new \ArrayObject();
        $result = $this->service(['totalCount' => 0, 'totalSize' => 0, 'byType' => []], $inserts)->capture();

        self::assertSame(0, $result['types']);
        self::assertSame([], $inserts->getArrayCopy());
    }

    #[Test]
    public function trendMapsRowsToTheSeriesShape(): void
    {
        $series = $this->service([], new \ArrayObject(), [
            ['captured_at' => '2026-06-19 00:00:00', 'unused_count' => 5, 'unused_size' => 500],
            ['captured_at' => '2026-06-18 00:00:00', 'unused_count' => 7, 'unused_size' => 700],
        ])->trend();

        self::assertSame([
            ['capturedAt' => '2026-06-19 00:00:00', 'count' => 5, 'size' => 500],
            ['capturedAt' => '2026-06-18 00:00:00', 'count' => 7, 'size' => 700],
        ], $series);
    }

    #[Test]
    public function trendQueriesTheRealTableByTypeAndAggregatedAcrossTypes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE asset_pilot_storage_snapshot (id INTEGER PRIMARY KEY, captured_at TEXT, type TEXT, unused_count INTEGER, unused_size INTEGER)',
        );
        foreach ([
            ['2026-06-18 00:00:00', 'image', 2, 100],
            ['2026-06-18 00:00:00', 'video', 1, 50],
            ['2026-06-19 00:00:00', 'image', 3, 200],
        ] as [$at, $type, $count, $size]) {
            $connection->insert('asset_pilot_storage_snapshot', ['captured_at' => $at, 'type' => $type, 'unused_count' => $count, 'unused_size' => $size]);
        }

        $service = new StorageTrendService($connection, $this->createMock(UnusedAssetFinderInterface::class));

        // type-filtered branch
        self::assertSame([
            ['capturedAt' => '2026-06-19 00:00:00', 'count' => 3, 'size' => 200],
            ['capturedAt' => '2026-06-18 00:00:00', 'count' => 2, 'size' => 100],
        ], $service->trend('image'));

        // all-types GROUP BY SUM branch (18th sums image+video)
        self::assertSame([
            ['capturedAt' => '2026-06-19 00:00:00', 'count' => 3, 'size' => 200],
            ['capturedAt' => '2026-06-18 00:00:00', 'count' => 3, 'size' => 150],
        ], $service->trend(null));
    }
}
