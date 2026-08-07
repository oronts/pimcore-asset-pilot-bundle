<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260721000000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260721000000::class)]
final class Version20260721000000Test extends TestCase
{
    #[Test]
    public function upAddsTheItemLeaseColumnsAndIndex(): void
    {
        $schema = new Schema();
        $migration = (new \ReflectionClass(Version20260721000000::class))->newInstanceWithoutConstructor();

        $migration->up($schema);

        $table = $schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM);
        self::assertTrue($table->hasColumn('claim_token'));
        self::assertTrue($table->hasColumn('lease_expires_at'));
        self::assertFalse($table->getColumn('claim_token')->getNotnull(), 'the lease token is nullable so unclaimed items carry none');
        self::assertFalse($table->getColumn('lease_expires_at')->getNotnull());
        self::assertSame(['status', 'lease_expires_at'], $table->getIndex('idx_operation_item_lease')->getColumns());
    }

    #[Test]
    public function downDropsOnlyTheItemLeaseColumnsAndIndex(): void
    {
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $migration = (new \ReflectionClass(Version20260721000000::class))->newInstanceWithoutConstructor();

        $migration->down($schema);

        $table = $schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM);
        self::assertFalse($table->hasColumn('claim_token'));
        self::assertFalse($table->hasColumn('lease_expires_at'));
        self::assertFalse($table->hasIndex('idx_operation_item_lease'));
        self::assertTrue($table->hasColumn('status'), 'the pre-existing item columns are left intact');
        self::assertTrue($table->hasIndex('idx_operation_item_run_status'));
    }
}
