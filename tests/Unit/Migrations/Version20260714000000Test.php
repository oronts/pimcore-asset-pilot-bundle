<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260714000000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Version20260714000000::class)]
#[CoversClass(Installer::class)]
class Version20260714000000Test extends TestCase
{
    private function migration(): Version20260714000000
    {
        return (new \ReflectionClass(Version20260714000000::class))->newInstanceWithoutConstructor();
    }

    private function v1Schema(): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable(Installer::TABLE_AUDIT_LOG);
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('asset_id', 'integer', ['notnull' => true]);
        $table->addColumn('asset_path_from', 'string', ['length' => 500, 'notnull' => true]);
        $table->addColumn('asset_path_to', 'string', ['length' => 500, 'notnull' => true]);
        $table->addColumn('object_id', 'integer', ['notnull' => true]);
        $table->addColumn('object_class', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('rule_name', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('trigger_type', 'string', ['length' => 50, 'notnull' => true]);
        $table->addColumn('status', 'string', ['length' => 50, 'notnull' => true]);
        $table->addColumn('error_message', 'text', ['notnull' => false]);
        $table->addColumn('duration_ms', 'integer', ['notnull' => false]);
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['status'], 'idx_audit_status');

        return $schema;
    }

    #[Test]
    public function upgradesTheReleasedV1SchemaToEveryOwnedTable(): void
    {
        $schema = $this->v1Schema();

        $this->migration()->up($schema);

        $tables = array_values(array_filter(
            array_map(static fn ($table): string => $table->getName(), $schema->getTables()),
            static fn (string $table): bool => str_starts_with($table, 'asset_pilot_'),
        ));
        sort($tables);

        self::assertSame([
            Installer::TABLE_AUDIT_LOG,
            Installer::TABLE_CHECKSUM,
            Installer::TABLE_INTEGRITY_LOG,
            Installer::TABLE_OPERATION_DELIVERY,
            Installer::TABLE_OPERATION_RUN,
            Installer::TABLE_OPERATION_RUN_ITEM,
            Installer::TABLE_QUARANTINE,
            Installer::TABLE_STORAGE_RUN,
            Installer::TABLE_STORAGE_SNAPSHOT,
        ], $tables);
        self::assertFalse($schema->getTable(Installer::TABLE_AUDIT_LOG)->hasIndex('idx_audit_status'));
        self::assertSame(765, $schema->getTable(Installer::TABLE_AUDIT_LOG)->getColumn('asset_path_from')->getLength());
        self::assertSame(765, $schema->getTable(Installer::TABLE_AUDIT_LOG)->getColumn('asset_path_to')->getLength());
        self::assertTrue($schema->getTable(Installer::TABLE_CHECKSUM)->hasIndex('uniq_checksum_asset'));
        self::assertTrue($schema->getTable(Installer::TABLE_CHECKSUM)->hasColumn('size_known'));
        self::assertTrue($schema->getTable(Installer::TABLE_STORAGE_RUN)->hasColumn('unknown_size_count'));
        self::assertTrue($schema->getTable(Installer::TABLE_STORAGE_SNAPSHOT)->hasColumn('unknown_size_count'));
    }

    #[Test]
    public function repairsWrongColumnAndIndexDefinitions(): void
    {
        $schema = $this->v1Schema();
        $this->migration()->up($schema);
        $checksum = $schema->getTable(Installer::TABLE_CHECKSUM);
        $checksum->modifyColumn('checksum', ['type' => Type::getType('text')]);
        $checksum->dropIndex('uniq_checksum_asset');
        $checksum->addIndex(['checksum'], 'uniq_checksum_asset');

        $this->migration()->up($schema);

        self::assertSame('string', Type::lookupName($checksum->getColumn('checksum')->getType()));
        self::assertSame(['asset_id'], $checksum->getIndex('uniq_checksum_asset')->getColumns());
        self::assertTrue($checksum->getIndex('uniq_checksum_asset')->isUnique());
    }

    #[Test]
    public function upIsIdempotent(): void
    {
        $schema = $this->v1Schema();
        $migration = $this->migration();

        $migration->up($schema);
        $first = serialize($schema);
        $migration->up($schema);

        self::assertSame($first, serialize($schema));
    }

    #[Test]
    public function backfillsOnlyHistoricalCompletedAssetsWithoutAnExistingMarker(): void
    {
        $migration = $this->migration();

        $migration->up($this->v1Schema());

        $queries = $migration->getSql();
        self::assertCount(1, $queries);
        self::assertStringContainsString('INNER JOIN asset_pilot_audit_log', $queries[0]->getStatement());
        self::assertStringContainsString('WHERE NOT EXISTS', $queries[0]->getStatement());
        self::assertSame([
            'propertyName' => 'asset_pilot_first_assignment',
            'completedStatus' => 'completed',
            'completedWithObserverErrorStatus' => 'completed_with_observer_error',
        ], $queries[0]->getParameters());
    }

    #[Test]
    public function postUpConvertsHistoricalStorageRowsIntoCompletedRuns(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE asset_pilot_storage_run (id INTEGER PRIMARY KEY AUTOINCREMENT, started_at TEXT NOT NULL, completed_at TEXT, status TEXT NOT NULL, total_count INTEGER NOT NULL, total_size INTEGER NOT NULL, unknown_size_count INTEGER NOT NULL DEFAULT 0, error_message TEXT)');
        $connection->executeStatement('CREATE TABLE asset_pilot_storage_snapshot (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, captured_at TEXT NOT NULL, type TEXT NOT NULL, unused_count INTEGER NOT NULL, unused_size INTEGER NOT NULL, unknown_size_count INTEGER NOT NULL DEFAULT 0, UNIQUE(run_id, type))');
        $connection->insert(Installer::TABLE_STORAGE_SNAPSHOT, ['run_id' => null, 'captured_at' => '2026-06-17 00:00:00', 'type' => 'image', 'unused_count' => 1, 'unused_size' => 25, 'unknown_size_count' => 1]);
        $connection->insert(Installer::TABLE_STORAGE_SNAPSHOT, ['run_id' => null, 'captured_at' => '2026-06-18 00:00:00', 'type' => 'image', 'unused_count' => 2, 'unused_size' => 100, 'unknown_size_count' => 0]);
        $connection->insert(Installer::TABLE_STORAGE_SNAPSHOT, ['run_id' => null, 'captured_at' => '2026-06-18 00:00:00', 'type' => 'video', 'unused_count' => 1, 'unused_size' => 50, 'unknown_size_count' => 0]);

        (new Version20260714000000($connection, new NullLogger()))->postUp(new Schema());

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_pilot_storage_run'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_pilot_storage_snapshot WHERE run_id IS NULL'));
        self::assertSame(
            [3, 150, 0],
            $connection->fetchNumeric("SELECT total_count, total_size, unknown_size_count FROM asset_pilot_storage_run WHERE completed_at = '2026-06-18 00:00:00'"),
        );
        self::assertSame(
            [1, 25, 1],
            $connection->fetchNumeric("SELECT total_count, total_size, unknown_size_count FROM asset_pilot_storage_run WHERE completed_at = '2026-06-17 00:00:00'"),
        );
    }

    #[Test]
    public function isScopedToThisBundle(): void
    {
        $method = new \ReflectionMethod(Version20260714000000::class, 'getBundleName');

        self::assertSame('OrontsAssetPilotBundle', $method->invoke($this->migration()));
    }
}
