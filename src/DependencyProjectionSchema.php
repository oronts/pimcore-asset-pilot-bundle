<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;

final class DependencyProjectionSchema
{
    public static function ensure(Schema $schema): void
    {
        $source = self::table($schema, Installer::TABLE_DEPENDENCY_SOURCE);
        self::column($source, 'id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        self::column($source, 'source_key', 'string', ['length' => 191, 'notnull' => true]);
        self::column($source, 'source_type', 'string', ['length' => 16, 'notnull' => true]);
        self::column($source, 'source_id', 'integer', ['notnull' => false]);
        self::column($source, 'state', 'string', ['length' => 16, 'notnull' => true]);
        self::column($source, 'revision', 'integer', ['notnull' => true]);
        self::column($source, 'generation', 'integer', ['notnull' => true]);
        self::column($source, 'source_modified_at', 'bigint', ['notnull' => false]);
        self::column($source, 'indexed_at', 'datetime', ['notnull' => false]);
        self::column($source, 'dirty_at', 'datetime', ['notnull' => false]);
        self::column($source, 'error_message', 'text', ['notnull' => false]);
        self::primary($source);
        self::index($source, 'idx_dependency_source_state', ['state', 'dirty_at']);
        self::index($source, 'idx_dependency_source_generation', ['generation']);
        self::index($source, 'idx_dependency_source_element', ['source_type', 'source_id']);
        self::index($source, 'uniq_dependency_source_key', ['source_key'], true);

        $edge = self::table($schema, Installer::TABLE_DEPENDENCY_EDGE);
        self::column($edge, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        self::column($edge, 'source_key', 'string', ['length' => 191, 'notnull' => true]);
        self::column($edge, 'source_type', 'string', ['length' => 16, 'notnull' => true]);
        self::column($edge, 'source_id', 'integer', ['notnull' => true]);
        self::column($edge, 'target_asset_id', 'integer', ['notnull' => true]);
        self::primary($edge);
        self::index($edge, 'idx_dependency_edge_target', ['target_asset_id']);
        self::index($edge, 'idx_dependency_edge_source', ['source_type', 'source_id']);
        self::index($edge, 'uniq_dependency_edge', ['source_key', 'target_asset_id'], true);

        $freshness = self::table($schema, Installer::TABLE_DEPENDENCY_FRESHNESS);
        self::column($freshness, 'id', 'integer', ['autoincrement' => false, 'notnull' => true]);
        self::column($freshness, 'status', 'string', ['length' => 24, 'notnull' => true]);
        self::column($freshness, 'generation', 'integer', ['notnull' => true, 'default' => 0]);
        self::column($freshness, 'cursor_type', 'string', ['length' => 16, 'notnull' => false]);
        self::column($freshness, 'cursor_id', 'integer', ['notnull' => true, 'default' => 0]);
        self::column($freshness, 'source_count', 'integer', ['notnull' => true, 'default' => 0]);
        self::column($freshness, 'edge_count', 'integer', ['notnull' => true, 'default' => 0]);
        self::column($freshness, 'started_at', 'datetime', ['notnull' => false]);
        self::column($freshness, 'completed_at', 'datetime', ['notnull' => false]);
        self::column($freshness, 'updated_at', 'datetime', ['notnull' => true]);
        self::column($freshness, 'error_message', 'text', ['notnull' => false]);
        self::primary($freshness);

        $fence = self::table($schema, Installer::TABLE_ASSET_DELETION_FENCE);
        self::column($fence, 'asset_id', 'integer', ['notnull' => true]);
        self::column($fence, 'owner_token', 'string', ['length' => 64, 'notnull' => true]);
        self::column($fence, 'operation', 'string', ['length' => 32, 'notnull' => true]);
        self::column($fence, 'created_at', 'datetime', ['notnull' => true]);
        self::column($fence, 'heartbeat_at', 'datetime', ['notnull' => true]);
        self::column($fence, 'expires_at', 'datetime', ['notnull' => true]);
        self::primary($fence, ['asset_id']);
        self::index($fence, 'idx_asset_deletion_fence_expires', ['expires_at']);
    }

    private static function table(Schema $schema, string $name): Table
    {
        return $schema->hasTable($name) ? $schema->getTable($name) : $schema->createTable($name);
    }

    /**
     * Reconcile one column to the desired definition. Mirrors Installer::ensureTable() semantics so a
     * malformed or partially deployed projection table is REPAIRED (wrong type/options replaced), not
     * merely left alone when the column already exists.
     *
     * @param array<string, mixed> $options
     */
    private static function column(Table $table, string $name, string $type, array $options): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, $type, $options);

            return;
        }

        $table->modifyColumn($name, ['type' => Type::getType($type), ...$options]);
    }

    /** @param list<string> $columns */
    private static function primary(Table $table, array $columns = ['id']): void
    {
        $primaryKey = $table->getPrimaryKey();
        if ($primaryKey !== null && $primaryKey->getColumns() === $columns) {
            return;
        }

        if ($primaryKey !== null) {
            $table->dropPrimaryKey();
        }
        $table->setPrimaryKey($columns);
    }

    /** @param list<string> $columns */
    private static function index(Table $table, string $name, array $columns, bool $unique = false): void
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
        } else {
            $table->addIndex($columns, $name);
        }
    }

    private function __construct() {}
}
