<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260715170000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260715170000::class)]
final class Version20260715170000Test extends TestCase
{
    #[Test]
    public function installerMarksThisAsTheLatestMigration(): void
    {
        $installer = (new \ReflectionClass(Installer::class))->newInstanceWithoutConstructor();

        self::assertSame(Version20260715170000::class, $installer->getLastMigrationVersionClassName());
    }

    #[Test]
    public function consolidatesHistoricalActionFailureRows(): void
    {
        $schema = new Schema();
        $schema->createTable(Installer::TABLE_AUDIT_LOG)->addColumn('status', 'string');
        $migration = (new \ReflectionClass(Version20260715170000::class))->newInstanceWithoutConstructor();

        $migration->up($schema);

        $queries = $migration->getSql();
        self::assertCount(1, $queries);
        self::assertSame(
            ['replacement' => 'completed_with_observer_error', 'retired' => 'action_failed'],
            $queries[0]->getParameters(),
        );
    }

    #[Test]
    public function doesNothingWhenTheAuditTableDoesNotExist(): void
    {
        $migration = (new \ReflectionClass(Version20260715170000::class))->newInstanceWithoutConstructor();

        $migration->up(new Schema());

        self::assertSame([], $migration->getSql());
    }
}
