<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle;

use Doctrine\DBAL\Connection;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
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

        if (!$schema->hasTable(self::TABLE_AUDIT_LOG)) {
            $table = $schema->createTable(self::TABLE_AUDIT_LOG);

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
            $table->addIndex(['asset_id'], 'idx_audit_asset_id');
            $table->addIndex(['object_id'], 'idx_audit_object_id');
            $table->addIndex(['rule_name'], 'idx_audit_rule_name');
            $table->addIndex(['status'], 'idx_audit_status');
            $table->addIndex(['created_at'], 'idx_audit_created_at');
        }

        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($currentSchema, $schema);

        $platform = $this->db->getDatabasePlatform();
        $sqlStatements = $platform->getAlterSchemaSQL($schemaDiff);

        foreach ($sqlStatements as $sql) {
            $this->db->executeStatement($sql);
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

        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($currentSchema, $schema);

        $platform = $this->db->getDatabasePlatform();
        $sqlStatements = $platform->getAlterSchemaSQL($schemaDiff);

        foreach ($sqlStatements as $sql) {
            $this->db->executeStatement($sql);
        }

        parent::uninstall();
    }

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }
}
