<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260715000000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260715000000::class)]
#[CoversClass(Installer::class)]
final class Version20260715000000Test extends TestCase
{
    private function migration(): Version20260715000000
    {
        return (new \ReflectionClass(Version20260715000000::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function createsTheOperationRunAndItemSchema(): void
    {
        $schema = new Schema();

        $this->migration()->up($schema);

        $run = $schema->getTable(Installer::TABLE_OPERATION_RUN);
        self::assertSame(['id'], $run->getPrimaryKey()?->getColumns());
        self::assertTrue($run->hasColumn('request_payload'));
        self::assertTrue($run->hasColumn('retry_of'));
        self::assertSame(['actor_type', 'actor_user_id', 'created_at'], $run->getIndex('idx_operation_run_actor_created')->getColumns());
        self::assertSame(['status', 'updated_at'], $run->getIndex('idx_operation_run_status_updated')->getColumns());
        self::assertSame(['retry_of'], $run->getIndex('idx_operation_run_retry')->getColumns());

        $item = $schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM);
        self::assertSame(['id'], $item->getPrimaryKey()?->getColumns());
        self::assertTrue($item->hasColumn('payload'));
        self::assertTrue($item->hasColumn('fingerprint'));
        self::assertSame(['run_id', 'status'], $item->getIndex('idx_operation_item_run_status')->getColumns());
        self::assertSame(['target_type', 'target_id'], $item->getIndex('idx_operation_item_target')->getColumns());
        self::assertSame(['run_id', 'item_key'], $item->getIndex('uniq_operation_item_run_key')->getColumns());
        self::assertTrue($item->getIndex('uniq_operation_item_run_key')->isUnique());
    }

    #[Test]
    public function upIsIdempotent(): void
    {
        $schema = new Schema();
        $migration = $this->migration();

        $migration->up($schema);
        $first = serialize($schema);
        $migration->up($schema);

        self::assertSame($first, serialize($schema));
    }

    #[Test]
    public function downRemovesOnlyTheOperationRunTables(): void
    {
        $schema = new Schema();
        $migration = $this->migration();
        $migration->up($schema);

        $migration->down($schema);

        self::assertFalse($schema->hasTable(Installer::TABLE_OPERATION_RUN));
        self::assertFalse($schema->hasTable(Installer::TABLE_OPERATION_RUN_ITEM));
        self::assertTrue($schema->hasTable(Installer::TABLE_AUDIT_LOG));
        self::assertTrue($schema->hasTable(Installer::TABLE_STORAGE_RUN));
    }

    #[Test]
    public function isScopedToThisBundle(): void
    {
        $method = new \ReflectionMethod(Version20260715000000::class, 'getBundleName');

        self::assertSame('OrontsAssetPilotBundle', $method->invoke($this->migration()));
    }
}
