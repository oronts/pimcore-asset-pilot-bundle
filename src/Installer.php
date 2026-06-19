<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Pimcore\Model\User\Permission\Definition;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

class Installer extends SettingsStoreAwareInstaller
{
    public const string TABLE_AUDIT_LOG = 'asset_pilot_audit_log';
    public const string TABLE_QUARANTINE = 'asset_pilot_quarantine';
    public const string TABLE_INTEGRITY_LOG = 'asset_pilot_integrity_log';
    public const string TABLE_CHECKSUM = 'asset_pilot_checksum';
    public const string TABLE_STORAGE_SNAPSHOT = 'asset_pilot_storage_snapshot';

    public function __construct(
        BundleInterface $bundle,
        protected readonly Connection $db,
    ) {
        parent::__construct($bundle);
    }

    public function install(): void
    {
        $schemaManager = $this->db->createSchemaManager();
        $currentSchema = $schemaManager->introspectSchema();
        $schema = clone $currentSchema;

        // Idempotent: create the table when absent, otherwise add only the missing columns/indexes
        // so a reinstall over a partial or older schema repairs it instead of being a no-op.
        $this->ensureTable($schema, self::TABLE_AUDIT_LOG, self::auditColumns(), self::auditIndexes());
        $this->ensureTable($schema, self::TABLE_QUARANTINE, self::quarantineColumns(), self::quarantineIndexes(), self::quarantineUniqueIndexes());
        $this->ensureTable($schema, self::TABLE_INTEGRITY_LOG, self::integrityLogColumns(), self::integrityLogIndexes());
        $this->ensureTable($schema, self::TABLE_CHECKSUM, self::checksumColumns(), self::checksumIndexes(), self::checksumUniqueIndexes());
        $this->ensureTable($schema, self::TABLE_STORAGE_SNAPSHOT, self::storageSnapshotColumns(), self::storageSnapshotIndexes());

        $this->applySchemaDiff($schemaManager, $currentSchema, $schema);

        foreach (AssetPilotPermission::cases() as $permission) {
            if (Definition::getByKey($permission->value) === null) {
                Definition::create($permission->value)
                    ->setCategory(AssetPilotPermission::CATEGORY)
                    ->save();
            }
        }

        parent::install();
    }

    public function uninstall(): void
    {
        $schemaManager = $this->db->createSchemaManager();
        $currentSchema = $schemaManager->introspectSchema();
        $schema = clone $currentSchema;

        foreach ([self::TABLE_AUDIT_LOG, self::TABLE_QUARANTINE, self::TABLE_INTEGRITY_LOG, self::TABLE_CHECKSUM, self::TABLE_STORAGE_SNAPSHOT] as $tableName) {
            if ($schema->hasTable($tableName)) {
                $schema->dropTable($tableName);
            }
        }

        $this->applySchemaDiff($schemaManager, $currentSchema, $schema);

        // Permission\Definition has no delete() (its DAO only saves), so remove the rows directly.
        $keyColumn = $this->db->getDatabasePlatform()->quoteIdentifier('key');
        foreach (AssetPilotPermission::cases() as $permission) {
            $this->db->executeStatement(
                sprintf('DELETE FROM users_permission_definitions WHERE %s = ?', $keyColumn),
                [$permission->value],
            );
        }

        parent::uninstall();
    }

    /**
     * Idempotently ensure a table has all desired columns and indexes (create when absent, add only
     * what is missing), shared by every owned table.
     *
     * @param list<array{0: string, 1: string, 2: array<string, mixed>}> $columns
     * @param array<string, list<string>>                                $indexes
     * @param array<string, list<string>>                                $uniqueIndexes
     */
    private function ensureTable(Schema $schema, string $name, array $columns, array $indexes, array $uniqueIndexes = []): void
    {
        $table = $schema->hasTable($name) ? $schema->getTable($name) : $schema->createTable($name);

        foreach ($columns as [$column, $type, $options]) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, $type, $options);
            }
        }

        if ($table->getPrimaryKey() === null) {
            $table->setPrimaryKey(['id']);
        }

        foreach ($indexes as $indexName => $indexColumns) {
            if (!$table->hasIndex($indexName)) {
                $table->addIndex($indexColumns, $indexName);
            }
        }

        foreach ($uniqueIndexes as $indexName => $indexColumns) {
            if (!$table->hasIndex($indexName)) {
                $table->addUniqueIndex($indexColumns, $indexName);
            }
        }
    }

    private function applySchemaDiff(AbstractSchemaManager $schemaManager, Schema $current, Schema $target): void
    {
        $schemaDiff = $schemaManager->createComparator()->compareSchemas($current, $target);
        $platform = $this->db->getDatabasePlatform();

        foreach ($platform->getAlterSchemaSQL($schemaDiff) as $sql) {
            $this->db->executeStatement($sql);
        }
    }

    /**
     * The desired audit-log columns: [name, doctrine type, options].
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected static function auditColumns(): array
    {
        return [
            ['id', 'integer', ['autoincrement' => true, 'notnull' => true]],
            ['asset_id', 'integer', ['notnull' => true]],
            ['asset_path_from', 'string', ['length' => 500, 'notnull' => true]],
            ['asset_path_to', 'string', ['length' => 500, 'notnull' => true]],
            ['object_id', 'integer', ['notnull' => true]],
            ['object_class', 'string', ['length' => 255, 'notnull' => true]],
            ['rule_name', 'string', ['length' => 255, 'notnull' => true]],
            ['trigger_type', 'string', ['length' => 50, 'notnull' => true]],
            ['status', 'string', ['length' => 50, 'notnull' => true]],
            ['error_message', 'text', ['notnull' => false]],
            ['duration_ms', 'integer', ['notnull' => false]],
            ['user_id', 'integer', ['notnull' => false]],
            ['created_at', 'datetime', ['notnull' => true]],
        ];
    }

    /**
     * The desired audit-log indexes, keyed by index name. The composites back the
     * per-asset-history and rule/status/time dashboard queries.
     *
     * @return array<string, list<string>>
     */
    protected static function auditIndexes(): array
    {
        return [
            'idx_audit_asset_id' => ['asset_id'],
            'idx_audit_object_id' => ['object_id'],
            'idx_audit_rule_name' => ['rule_name'],
            'idx_audit_status' => ['status'],
            'idx_audit_created_at' => ['created_at'],
            'idx_audit_asset_status' => ['asset_id', 'status'],
            'idx_audit_rule_status_created' => ['rule_name', 'status', 'created_at'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected static function quarantineColumns(): array
    {
        return [
            ['id', 'integer', ['autoincrement' => true, 'notnull' => true]],
            ['asset_id', 'integer', ['notnull' => true]],
            ['original_path', 'string', ['length' => 765, 'notnull' => true]],
            ['quarantined_at', 'datetime', ['notnull' => true]],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function quarantineIndexes(): array
    {
        return [
            'idx_quarantine_at' => ['quarantined_at'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function quarantineUniqueIndexes(): array
    {
        return [
            'uniq_quarantine_asset' => ['asset_id'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected static function integrityLogColumns(): array
    {
        return [
            ['id', 'integer', ['autoincrement' => true, 'notnull' => true]],
            ['asset_id', 'integer', ['notnull' => true]],
            ['from_version', 'integer', ['notnull' => false]],
            ['to_version', 'integer', ['notnull' => false]],
            ['checker', 'string', ['length' => 100, 'notnull' => true]],
            ['status', 'string', ['length' => 20, 'notnull' => true]],
            ['created_at', 'datetime', ['notnull' => true]],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function integrityLogIndexes(): array
    {
        return [
            'idx_integrity_asset' => ['asset_id'],
            'idx_integrity_asset_status' => ['asset_id', 'status'],
        ];
    }

    /**
     * The owned content-hash index: one row per scanned asset. asset_id is the PK (one hash per
     * asset); the checksum index backs the duplicate GROUP BY and per-group lookup.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected static function checksumColumns(): array
    {
        return [
            ['id', 'integer', ['autoincrement' => true, 'notnull' => true]],
            ['asset_id', 'integer', ['notnull' => true]],
            ['checksum', 'string', ['length' => 64, 'notnull' => true]],
            ['file_size', 'bigint', ['notnull' => true]],
            ['indexed_at', 'datetime', ['notnull' => true]],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function checksumIndexes(): array
    {
        return [
            'idx_checksum' => ['checksum'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function checksumUniqueIndexes(): array
    {
        return [
            'uniq_checksum_asset' => ['asset_id'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected static function storageSnapshotColumns(): array
    {
        return [
            ['id', 'integer', ['autoincrement' => true, 'notnull' => true]],
            ['captured_at', 'datetime', ['notnull' => true]],
            ['type', 'string', ['length' => 50, 'notnull' => true]],
            ['unused_count', 'integer', ['notnull' => true]],
            ['unused_size', 'bigint', ['notnull' => true]],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function storageSnapshotIndexes(): array
    {
        return [
            'idx_snapshot_captured' => ['captured_at'],
            'idx_snapshot_type_captured' => ['type', 'captured_at'],
        ];
    }

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }
}
