<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260716120000;
use Oronts\AssetPilotBundle\Migrations\Version20260717000000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260716120000::class)]
final class Version20260716120000Test extends TestCase
{
    #[Test]
    public function installerMarksThisAsTheLatestMigration(): void
    {
        $installer = (new \ReflectionClass(Installer::class))->newInstanceWithoutConstructor();

        self::assertSame(Version20260717000000::class, $installer->getLastMigrationVersionClassName());
    }

    #[Test]
    public function createsTheApplyPlanClaimTable(): void
    {
        $schema = new Schema();
        $migration = (new \ReflectionClass(Version20260716120000::class))->newInstanceWithoutConstructor();

        $migration->up($schema);

        $table = $schema->getTable(Installer::TABLE_APPLY_PLAN_CLAIM);
        self::assertSame(['id'], $table->getPrimaryKey()?->getColumns());
        self::assertTrue($table->hasColumn('claimed_at'));
        self::assertTrue($table->hasColumn('expires_at'));
        self::assertSame(['expires_at'], $table->getIndex('idx_apply_plan_claim_expires')->getColumns());
        self::assertTrue($schema->getTable(Installer::TABLE_AUDIT_LOG)->hasColumn('durable_observer_failures'));
    }

    #[Test]
    public function downDropsOnlyTheApplyPlanClaimTable(): void
    {
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $migration = (new \ReflectionClass(Version20260716120000::class))->newInstanceWithoutConstructor();

        $migration->down($schema);

        self::assertFalse($schema->hasTable(Installer::TABLE_APPLY_PLAN_CLAIM));
        self::assertTrue($schema->hasTable(Installer::TABLE_AUDIT_LOG));
        self::assertFalse($schema->getTable(Installer::TABLE_AUDIT_LOG)->hasColumn('durable_observer_failures'));
    }
}
