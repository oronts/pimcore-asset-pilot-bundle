<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260715160000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260715160000::class)]
final class Version20260715160000Test extends TestCase
{
    #[Test]
    public function migrationAddsAndRemovesJournalSchema(): void
    {
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $migration = (new \ReflectionClass(Version20260715160000::class))->newInstanceWithoutConstructor();

        $migration->down($schema);
        self::assertFalse($schema->hasTable(Installer::TABLE_OPERATION_DELIVERY));
        $audit = $schema->getTable(Installer::TABLE_AUDIT_LOG);
        self::assertFalse($audit->hasColumn('intent_payload'));
        self::assertFalse($audit->hasColumn('committed_at'));

        $migration->up($schema);
        self::assertTrue($schema->hasTable(Installer::TABLE_OPERATION_DELIVERY));
        self::assertTrue($schema->getTable(Installer::TABLE_AUDIT_LOG)->hasColumn('intent_payload'));
        self::assertTrue($schema->getTable(Installer::TABLE_AUDIT_LOG)->hasIndex('idx_audit_recovery'));
    }
}
