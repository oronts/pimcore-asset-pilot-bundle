<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260716180000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260716180000::class)]
class Version20260716180000Test extends TestCase
{
    #[Test]
    public function createsIndexedProjectionTablesAndCanRemoveOnlyThoseTables(): void
    {
        $schema = new Schema();
        $migration = (new \ReflectionClass(Version20260716180000::class))->newInstanceWithoutConstructor();

        $migration->up($schema);

        self::assertTrue($schema->hasTable(Installer::TABLE_DEPENDENCY_SOURCE));
        self::assertTrue($schema->hasTable(Installer::TABLE_DEPENDENCY_EDGE));
        self::assertTrue($schema->hasTable(Installer::TABLE_DEPENDENCY_FRESHNESS));
        self::assertTrue($schema->getTable(Installer::TABLE_DEPENDENCY_SOURCE)->hasIndex('uniq_dependency_source_key'));
        self::assertTrue($schema->getTable(Installer::TABLE_DEPENDENCY_EDGE)->hasIndex('idx_dependency_edge_target'));
        self::assertTrue($schema->getTable(Installer::TABLE_DEPENDENCY_EDGE)->hasIndex('uniq_dependency_edge'));

        $migration->down($schema);

        self::assertFalse($schema->hasTable(Installer::TABLE_DEPENDENCY_SOURCE));
        self::assertFalse($schema->hasTable(Installer::TABLE_DEPENDENCY_EDGE));
        self::assertFalse($schema->hasTable(Installer::TABLE_DEPENDENCY_FRESHNESS));
        self::assertTrue($schema->hasTable(Installer::TABLE_AUDIT_LOG));
    }
}
