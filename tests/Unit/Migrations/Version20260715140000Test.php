<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260715140000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260715140000::class)]
#[CoversClass(Installer::class)]
final class Version20260715140000Test extends TestCase
{
    private function migration(): Version20260715140000
    {
        return (new \ReflectionClass(Version20260715140000::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function upgradesLegacyOperationRunsWithResumableStateAndBlockedCounts(): void
    {
        $schema = new Schema();
        $previousMigration = (new \ReflectionClass(\Oronts\AssetPilotBundle\Migrations\Version20260715000000::class))->newInstanceWithoutConstructor();
        $previousMigration->up($schema);
        $run = $schema->getTable(Installer::TABLE_OPERATION_RUN);
        $item = $schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM);
        $run->dropColumn('blocked_count');
        $item->dropColumn('state_payload');

        $this->migration()->up($schema);

        self::assertSame(0, $run->getColumn('blocked_count')->getDefault());
        self::assertSame('{}', $item->getColumn('state_payload')->getDefault());
        self::assertTrue($item->getColumn('state_payload')->getNotnull());
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
    public function downRemovesOnlySagaStateColumns(): void
    {
        $schema = new Schema();
        $migration = $this->migration();
        $migration->up($schema);

        $migration->down($schema);

        self::assertFalse($schema->getTable(Installer::TABLE_OPERATION_RUN)->hasColumn('blocked_count'));
        self::assertFalse($schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM)->hasColumn('state_payload'));
        self::assertTrue($schema->getTable(Installer::TABLE_OPERATION_RUN)->hasColumn('request_payload'));
        self::assertTrue($schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM)->hasColumn('payload'));
    }

    #[Test]
    public function installerAdvancesBeyondThisMigration(): void
    {
        $installer = new class () extends Installer {
            public function __construct() {}
        };

        self::assertNotSame(Version20260715140000::class, $installer->getLastMigrationVersionClassName());
    }

    #[Test]
    public function isScopedToThisBundle(): void
    {
        $method = new \ReflectionMethod(Version20260715140000::class, 'getBundleName');

        self::assertSame('OrontsAssetPilotBundle', $method->invoke($this->migration()));
    }
}
