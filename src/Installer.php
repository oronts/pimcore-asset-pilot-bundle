<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\QuarantineStatus;
use Oronts\AssetPilotBundle\Migrations\Version20260721000000;
use Pimcore\Bundle\StaticResolverBundle\Lib\CacheResolverInterface;
use Pimcore\Bundle\StudioBackendBundle\Util\Constant\CacheKeys;
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
    public const string TABLE_STORAGE_RUN = 'asset_pilot_storage_run';
    public const string TABLE_OPERATION_RUN = 'asset_pilot_operation_run';
    public const string TABLE_OPERATION_RUN_ITEM = 'asset_pilot_operation_run_item';
    public const string TABLE_OPERATION_DELIVERY = 'asset_pilot_operation_delivery';
    public const string TABLE_APPLY_PLAN_CLAIM = 'asset_pilot_apply_plan_claim';
    public const string TABLE_DEPENDENCY_SOURCE = 'asset_pilot_dependency_source';
    public const string TABLE_DEPENDENCY_EDGE = 'asset_pilot_dependency_edge';
    public const string TABLE_DEPENDENCY_FRESHNESS = 'asset_pilot_dependency_freshness';
    public const string TABLE_ASSET_DELETION_FENCE = 'asset_pilot_asset_deletion_fence';

    public function __construct(
        BundleInterface $bundle,
        protected readonly Connection $db,
        protected readonly CacheResolverInterface $cacheResolver,
    ) {
        parent::__construct($bundle);
    }

    public function install(): void
    {
        $schemaManager = $this->db->createSchemaManager();
        $currentSchema = $schemaManager->introspectSchema();
        $schema = clone $currentSchema;

        self::ensureCurrentSchema($schema);

        $this->applySchemaDiff($schemaManager, $currentSchema, $schema);

        $this->installPermissions();
        $this->invalidatePermissionCache();

        parent::install();
    }

    public function uninstall(): void
    {
        $schemaManager = $this->db->createSchemaManager();
        $currentSchema = $schemaManager->introspectSchema();
        $schema = clone $currentSchema;

        foreach ([self::TABLE_ASSET_DELETION_FENCE, self::TABLE_DEPENDENCY_EDGE, self::TABLE_DEPENDENCY_SOURCE, self::TABLE_DEPENDENCY_FRESHNESS, self::TABLE_APPLY_PLAN_CLAIM, self::TABLE_OPERATION_DELIVERY, self::TABLE_AUDIT_LOG, self::TABLE_QUARANTINE, self::TABLE_INTEGRITY_LOG, self::TABLE_CHECKSUM, self::TABLE_STORAGE_SNAPSHOT, self::TABLE_STORAGE_RUN, self::TABLE_OPERATION_RUN_ITEM, self::TABLE_OPERATION_RUN] as $tableName) {
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
        $this->invalidatePermissionCache();

        parent::uninstall();
    }

    protected function installPermissions(): void
    {
        foreach (AssetPilotPermission::cases() as $permission) {
            $definition = Definition::getByKey($permission->value) ?? Definition::create($permission->value);
            $definition->setCategory(AssetPilotPermission::CATEGORY)->save();

            $persisted = Definition::getByKey($permission->value);
            if ($persisted === null || $persisted->getCategory() !== AssetPilotPermission::CATEGORY) {
                throw new \RuntimeException(sprintf('Failed to persist permission definition "%s".', $permission->value));
            }
        }
    }

    protected function invalidatePermissionCache(): void
    {
        $this->cacheResolver->remove(CacheKeys::USER_PERMISSIONS->value);
    }

    public static function ensureCurrentSchema(Schema $schema): void
    {
        self::ensureTable($schema, self::TABLE_AUDIT_LOG, self::auditColumns(), self::auditIndexes());
        self::ensureTable($schema, self::TABLE_QUARANTINE, self::quarantineColumns(), self::quarantineIndexes(), self::quarantineUniqueIndexes());
        self::ensureTable($schema, self::TABLE_INTEGRITY_LOG, self::integrityLogColumns(), self::integrityLogIndexes());
        self::ensureTable($schema, self::TABLE_CHECKSUM, self::checksumColumns(), self::checksumIndexes(), self::checksumUniqueIndexes());
        self::ensureTable($schema, self::TABLE_STORAGE_RUN, self::storageRunColumns(), self::storageRunIndexes());
        self::ensureTable($schema, self::TABLE_STORAGE_SNAPSHOT, self::storageSnapshotColumns(), self::storageSnapshotIndexes(), self::storageSnapshotUniqueIndexes());
        self::ensureTable($schema, self::TABLE_OPERATION_RUN, self::operationRunColumns(), self::operationRunIndexes(), self::operationRunUniqueIndexes());
        self::ensureTable($schema, self::TABLE_OPERATION_RUN_ITEM, self::operationRunItemColumns(), self::operationRunItemIndexes(), self::operationRunItemUniqueIndexes());
        self::ensureTable($schema, self::TABLE_OPERATION_DELIVERY, self::operationDeliveryColumns(), self::operationDeliveryIndexes(), self::operationDeliveryUniqueIndexes());
        self::ensureTable($schema, self::TABLE_APPLY_PLAN_CLAIM, self::applyPlanClaimColumns(), self::applyPlanClaimIndexes());
        DependencyProjectionSchema::ensure($schema);

        $auditTable = $schema->getTable(self::TABLE_AUDIT_LOG);
        if ($auditTable->hasIndex('idx_audit_status')) {
            $auditTable->dropIndex('idx_audit_status');
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: array<string, mixed>}> $columns
     * @param array<string, list<string>>                                $indexes
     * @param array<string, list<string>>                                $uniqueIndexes
     */
    private static function ensureTable(Schema $schema, string $name, array $columns, array $indexes, array $uniqueIndexes = []): void
    {
        $table = $schema->hasTable($name) ? $schema->getTable($name) : $schema->createTable($name);

        foreach ($columns as [$column, $type, $options]) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, $type, $options);
                continue;
            }

            $table->modifyColumn($column, ['type' => Type::getType($type), ...$options]);
        }

        $primaryKey = $table->getPrimaryKey();
        if ($primaryKey === null || $primaryKey->getColumns() !== ['id']) {
            $table->dropPrimaryKey();
            $table->setPrimaryKey(['id']);
        }

        foreach ($indexes as $indexName => $indexColumns) {
            self::ensureIndex($table, $indexName, $indexColumns, false);
        }

        foreach ($uniqueIndexes as $indexName => $indexColumns) {
            self::ensureIndex($table, $indexName, $indexColumns, true);
        }
    }

    /** @param list<string> $columns */
    private static function ensureIndex(Table $table, string $name, array $columns, bool $unique): void
    {
        if ($table->hasIndex($name)) {
            $index = $table->getIndex($name);
            if ($index->getColumns() === $columns && $index->isUnique() === $unique) {
                return;
            }
            $table->dropIndex($name);
        }

        if ($unique) {
            $table->addUniqueIndex($columns, $name);

            return;
        }

        $table->addIndex($columns, $name);
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
            ['asset_path_from', 'string', ['length' => 765, 'notnull' => true]],
            ['asset_path_to', 'string', ['length' => 765, 'notnull' => true]],
            ['object_id', 'integer', ['notnull' => true]],
            ['object_class', 'string', ['length' => 255, 'notnull' => true]],
            ['rule_name', 'string', ['length' => 255, 'notnull' => true]],
            ['trigger_type', 'string', ['length' => 50, 'notnull' => true]],
            ['status', 'string', ['length' => 50, 'notnull' => true]],
            ['error_message', 'text', ['notnull' => false]],
            ['durable_observer_failures', 'text', ['notnull' => false]],
            ['duration_ms', 'integer', ['notnull' => false]],
            ['user_id', 'integer', ['notnull' => false]],
            ['created_at', 'datetime', ['notnull' => true]],
            ['operation_kind', 'string', ['length' => 20, 'notnull' => false]],
            ['actor_type', 'string', ['length' => 20, 'notnull' => false]],
            ['parent_audit_id', 'integer', ['notnull' => false]],
            ['intent_payload', 'text', ['notnull' => false]],
            ['schema_version', 'integer', ['notnull' => false]],
            ['updated_at', 'datetime', ['notnull' => false]],
            ['committed_at', 'datetime', ['notnull' => false]],
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
            'idx_audit_created_at' => ['created_at'],
            'idx_audit_asset_status' => ['asset_id', 'status'],
            'idx_audit_rule_status_created' => ['rule_name', 'status', 'created_at'],
            'idx_audit_status_created' => ['status', 'created_at'],
            'idx_audit_class_created' => ['object_class', 'created_at'],
            'idx_audit_recovery' => ['status', 'updated_at'],
            'idx_audit_parent' => ['parent_audit_id'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    protected static function operationDeliveryColumns(): array
    {
        return [
            ['id', 'string', ['length' => 64, 'notnull' => true]],
            ['operation_id', 'integer', ['notnull' => true]],
            ['delivery_key', 'string', ['length' => 191, 'notnull' => true]],
            ['observer_id', 'string', ['length' => 191, 'notnull' => true]],
            ['outcome', 'string', ['length' => 16, 'notnull' => true]],
            ['intent_payload', 'text', ['notnull' => true]],
            ['payload', 'text', ['notnull' => true]],
            ['status', 'string', ['length' => 20, 'notnull' => true]],
            ['attempts', 'integer', ['notnull' => true, 'default' => 0]],
            ['available_at', 'datetime', ['notnull' => true]],
            ['lock_token', 'string', ['length' => 64, 'notnull' => false]],
            ['locked_until', 'datetime', ['notnull' => false]],
            ['last_error', 'text', ['notnull' => false]],
            ['created_at', 'datetime', ['notnull' => true]],
            ['updated_at', 'datetime', ['notnull' => true]],
            ['delivered_at', 'datetime', ['notnull' => false]],
            ['audit_reconciled_at', 'datetime', ['notnull' => false]],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function operationDeliveryIndexes(): array
    {
        return [
            'idx_operation_delivery_due' => ['status', 'available_at'],
            'idx_operation_delivery_reclaim' => ['status', 'locked_until'],
            'idx_operation_delivery_operation' => ['operation_id', 'status'],
            'idx_operation_delivery_audit_reconcile' => ['status', 'audit_reconciled_at'],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function operationDeliveryUniqueIndexes(): array
    {
        return ['uniq_operation_delivery_key' => ['operation_id', 'observer_id', 'delivery_key']];
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
            ['status', 'string', ['length' => 20, 'notnull' => true, 'default' => QuarantineStatus::Committed->value]],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function quarantineIndexes(): array
    {
        return [
            'idx_quarantine_at' => ['quarantined_at'],
            'idx_quarantine_status_at' => ['status', 'quarantined_at'],
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
            ['size_known', 'boolean', ['notnull' => true, 'default' => false]],
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
            ['run_id', 'integer', ['notnull' => false]],
            ['captured_at', 'datetime', ['notnull' => true]],
            ['type', 'string', ['length' => 50, 'notnull' => true]],
            ['unused_count', 'integer', ['notnull' => true]],
            ['unused_size', 'bigint', ['notnull' => true]],
            ['unknown_size_count', 'integer', ['notnull' => true, 'default' => 0]],
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
            'idx_snapshot_run' => ['run_id'],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function storageSnapshotUniqueIndexes(): array
    {
        return ['uniq_snapshot_run_type' => ['run_id', 'type']];
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    protected static function storageRunColumns(): array
    {
        return [
            ['id', 'integer', ['autoincrement' => true, 'notnull' => true]],
            ['started_at', 'datetime', ['notnull' => true]],
            ['completed_at', 'datetime', ['notnull' => false]],
            ['status', 'string', ['length' => 20, 'notnull' => true]],
            ['total_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['total_size', 'bigint', ['notnull' => true, 'default' => 0]],
            ['unknown_size_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['error_message', 'string', ['length' => 255, 'notnull' => false]],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function storageRunIndexes(): array
    {
        return [
            'idx_storage_run_status_completed' => ['status', 'completed_at'],
            'idx_storage_run_started' => ['started_at'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    protected static function operationRunColumns(): array
    {
        return [
            ['id', 'string', ['length' => 32, 'notnull' => true]],
            ['kind', 'string', ['length' => 64, 'notnull' => true]],
            ['actor_type', 'string', ['length' => 20, 'notnull' => true]],
            ['actor_user_id', 'integer', ['notnull' => false]],
            ['status', 'string', ['length' => 32, 'notnull' => true]],
            ['total_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['processed_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['succeeded_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['skipped_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['blocked_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['failed_count', 'integer', ['notnull' => true, 'default' => 0]],
            ['attempt', 'integer', ['notnull' => true, 'default' => 1]],
            ['retry_of', 'string', ['length' => 32, 'notnull' => false]],
            ['request_payload', 'text', ['notnull' => true]],
            ['error_message', 'text', ['notnull' => false]],
            ['created_at', 'datetime', ['notnull' => true]],
            ['started_at', 'datetime', ['notnull' => false]],
            ['updated_at', 'datetime', ['notnull' => true]],
            ['completed_at', 'datetime', ['notnull' => false]],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function operationRunIndexes(): array
    {
        return [
            'idx_operation_run_actor_created' => ['actor_type', 'actor_user_id', 'created_at'],
            'idx_operation_run_status_updated' => ['status', 'updated_at'],
        ];
    }
    /** @return array<string, list<string>> */
    protected static function operationRunUniqueIndexes(): array
    {
        return ['uniq_operation_run_retry' => ['retry_of']];
    }


    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    protected static function operationRunItemColumns(): array
    {
        return [
            ['id', 'integer', ['autoincrement' => true, 'notnull' => true]],
            ['run_id', 'string', ['length' => 32, 'notnull' => true]],
            ['item_key', 'string', ['length' => 191, 'notnull' => true]],
            ['target_type', 'string', ['length' => 32, 'notnull' => true]],
            ['target_id', 'integer', ['notnull' => false]],
            ['fingerprint', 'string', ['length' => 64, 'notnull' => false]],
            ['payload', 'text', ['notnull' => true]],
            ['state_payload', 'text', ['notnull' => true, 'default' => '{}']],
            ['status', 'string', ['length' => 32, 'notnull' => true]],
            ['attempts', 'integer', ['notnull' => true, 'default' => 0]],
            ['result_payload', 'text', ['notnull' => false]],
            ['error_message', 'text', ['notnull' => false]],
            ['created_at', 'datetime', ['notnull' => true]],
            ['updated_at', 'datetime', ['notnull' => true]],
            ['completed_at', 'datetime', ['notnull' => false]],
            ['claim_token', 'string', ['length' => 64, 'notnull' => false]],
            ['lease_expires_at', 'datetime', ['notnull' => false]],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function operationRunItemIndexes(): array
    {
        return [
            'idx_operation_item_run_status' => ['run_id', 'status'],
            'idx_operation_item_target' => ['target_type', 'target_id'],
            'idx_operation_item_lease' => ['status', 'lease_expires_at'],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function operationRunItemUniqueIndexes(): array
    {
        return ['uniq_operation_item_run_key' => ['run_id', 'item_key']];
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    protected static function applyPlanClaimColumns(): array
    {
        return [
            ['id', 'string', ['length' => 64, 'notnull' => true]],
            ['claimed_at', 'datetime', ['notnull' => true]],
            ['expires_at', 'datetime', ['notnull' => true]],
        ];
    }

    /** @return array<string, list<string>> */
    protected static function applyPlanClaimIndexes(): array
    {
        return ['idx_apply_plan_claim_expires' => ['expires_at']];
    }

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }

    /**
     * A fresh install creates the current schema in full, so Pimcore marks every migration up to this
     * version as already applied; existing installs pick up later schema deltas via the migrations.
     */
    public function getLastMigrationVersionClassName(): ?string
    {
        return Version20260721000000::class;
    }
}
