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
        $table = $schema->hasTable(self::TABLE_AUDIT_LOG)
            ? $schema->getTable(self::TABLE_AUDIT_LOG)
            : $schema->createTable(self::TABLE_AUDIT_LOG);

        foreach (self::auditColumns() as [$name, $type, $options]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        }

        if ($table->getPrimaryKey() === null) {
            $table->setPrimaryKey(['id']);
        }

        foreach (self::auditIndexes() as $indexName => $columns) {
            if (!$table->hasIndex($indexName)) {
                $table->addIndex($columns, $indexName);
            }
        }

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

        if ($schema->hasTable(self::TABLE_AUDIT_LOG)) {
            $schema->dropTable(self::TABLE_AUDIT_LOG);
        }

        $this->applySchemaDiff($schemaManager, $currentSchema, $schema);

        foreach (AssetPilotPermission::cases() as $permission) {
            Definition::getByKey($permission->value)?->delete();
        }

        parent::uninstall();
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

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }
}
