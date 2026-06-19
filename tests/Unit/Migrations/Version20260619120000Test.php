<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260619120000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260619120000::class)]
class Version20260619120000Test extends TestCase
{
    private function migration(): Version20260619120000
    {
        return (new \ReflectionClass(Version20260619120000::class))->newInstanceWithoutConstructor();
    }

    private function schemaWithAuditTable(): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable(Installer::TABLE_AUDIT_LOG);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('status', 'string', ['length' => 50]);
        $table->addColumn('object_class', 'string', ['length' => 255]);
        $table->addColumn('created_at', 'datetime');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['status'], 'idx_audit_status');

        return $schema;
    }

    #[Test]
    public function upAddsTheCompositeAuditIndexes(): void
    {
        $schema = $this->schemaWithAuditTable();
        $this->migration()->up($schema);

        $table = $schema->getTable(Installer::TABLE_AUDIT_LOG);
        self::assertSame(['status', 'created_at'], $table->getIndex('idx_audit_status_created')->getColumns());
        self::assertSame(['object_class', 'created_at'], $table->getIndex('idx_audit_class_created')->getColumns());
        self::assertFalse($table->hasIndex('idx_audit_status'), 'the superseded single-column index is dropped');
    }

    #[Test]
    public function upIsIdempotentWhenAnIndexAlreadyExists(): void
    {
        $schema = $this->schemaWithAuditTable();
        $schema->getTable(Installer::TABLE_AUDIT_LOG)->addIndex(['status', 'created_at'], 'idx_audit_status_created');

        $this->migration()->up($schema);

        self::assertTrue($schema->getTable(Installer::TABLE_AUDIT_LOG)->hasIndex('idx_audit_class_created'));
    }

    #[Test]
    public function downRemovesTheCompositeAuditIndexes(): void
    {
        $schema = $this->schemaWithAuditTable();
        $migration = $this->migration();
        $migration->up($schema);
        $migration->down($schema);

        $table = $schema->getTable(Installer::TABLE_AUDIT_LOG);
        self::assertFalse($table->hasIndex('idx_audit_status_created'));
        self::assertFalse($table->hasIndex('idx_audit_class_created'));
        self::assertTrue($table->hasIndex('idx_audit_status'), 'down() restores the superseded index');
    }

    #[Test]
    public function upSkipsWhenTheAuditTableIsAbsent(): void
    {
        $schema = new Schema();
        $this->migration()->up($schema);

        self::assertFalse($schema->hasTable(Installer::TABLE_AUDIT_LOG));
    }

    #[Test]
    public function isScopedToThisBundle(): void
    {
        $method = new \ReflectionMethod(Version20260619120000::class, 'getBundleName');

        self::assertSame('OrontsAssetPilotBundle', $method->invoke($this->migration()));
    }
}
