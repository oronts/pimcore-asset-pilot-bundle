<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\IntegerType;
use Oronts\AssetPilotBundle\DependencyProjectionSchema;
use Oronts\AssetPilotBundle\Installer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyProjectionSchema::class)]
class DependencyProjectionSchemaTest extends TestCase
{
    #[Test]
    public function ensureRepairsAMalformedProjectionSourceTableInsteadOfLeavingItBroken(): void
    {
        // A partially deployed / malformed source table: nullable string id, no primary key, and a
        // non-unique, wrong-column index reusing the canonical unique index name.
        $schema = new Schema();
        $table = $schema->createTable(Installer::TABLE_DEPENDENCY_SOURCE);
        $table->addColumn('id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('source_key', 'string', ['length' => 191, 'notnull' => true]);
        $table->addColumn('source_type', 'string', ['length' => 16, 'notnull' => true]);
        $table->addColumn('source_id', 'integer', ['notnull' => false]);
        $table->addColumn('state', 'string', ['length' => 16, 'notnull' => true]);
        $table->addColumn('revision', 'integer', ['notnull' => true]);
        $table->addColumn('generation', 'integer', ['notnull' => true]);
        $table->addIndex(['source_id'], 'uniq_dependency_source_key');

        DependencyProjectionSchema::ensure($schema);

        $repaired = $schema->getTable(Installer::TABLE_DEPENDENCY_SOURCE);

        self::assertInstanceOf(IntegerType::class, $repaired->getColumn('id')->getType(), 'the id column type is repaired to integer');
        self::assertTrue($repaired->getColumn('id')->getNotnull(), 'the id column is repaired to NOT NULL');

        $primaryKey = $repaired->getPrimaryKey();
        self::assertNotNull($primaryKey, 'a missing primary key is created');
        self::assertSame(['id'], $primaryKey->getColumns(), 'the primary key is on id');

        $unique = $repaired->getIndex('uniq_dependency_source_key');
        self::assertTrue($unique->isUnique(), 'a non-unique same-named index is replaced with the unique one');
        self::assertSame(['source_key'], $unique->getColumns(), 'the unique index is repaired to the source_key column');
    }

    #[Test]
    public function ensureIsIdempotentOnAnAlreadyCorrectSchema(): void
    {
        $schema = new Schema();
        DependencyProjectionSchema::ensure($schema);
        DependencyProjectionSchema::ensure($schema);

        $source = $schema->getTable(Installer::TABLE_DEPENDENCY_SOURCE);
        self::assertSame(['id'], $source->getPrimaryKey()?->getColumns());
        self::assertTrue($source->getIndex('uniq_dependency_source_key')->isUnique());
        self::assertTrue($schema->hasTable(Installer::TABLE_DEPENDENCY_EDGE));
        self::assertTrue($schema->hasTable(Installer::TABLE_DEPENDENCY_FRESHNESS));
    }

    #[Test]
    public function ensureCreatesTheAssetDeletionFenceTableWithAssetIdPrimaryKey(): void
    {
        $schema = new Schema();
        DependencyProjectionSchema::ensure($schema);

        self::assertTrue($schema->hasTable(Installer::TABLE_ASSET_DELETION_FENCE));
        $fence = $schema->getTable(Installer::TABLE_ASSET_DELETION_FENCE);

        foreach (['asset_id', 'owner_token', 'operation', 'created_at', 'heartbeat_at', 'expires_at'] as $column) {
            self::assertTrue($fence->hasColumn($column), "fence table has {$column}");
        }
        self::assertInstanceOf(IntegerType::class, $fence->getColumn('asset_id')->getType());
        self::assertSame(['asset_id'], $fence->getPrimaryKey()?->getColumns(), 'one deleting owner per asset');
        self::assertTrue($fence->hasIndex('idx_asset_deletion_fence_expires'), 'the expiry scan index exists');
    }
}
