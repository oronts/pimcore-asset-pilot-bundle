<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\QuarantineStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260715120000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260715120000::class)]
#[CoversClass(Installer::class)]
final class Version20260715120000Test extends TestCase
{
    private function migration(): Version20260715120000
    {
        return (new \ReflectionClass(Version20260715120000::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function upgradesTheLegacyQuarantineTableWithCommittedLifecycleState(): void
    {
        $schema = new Schema();
        $table = $schema->createTable(Installer::TABLE_QUARANTINE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('asset_id', 'integer');
        $table->addColumn('original_path', 'string', ['length' => 765]);
        $table->addColumn('quarantined_at', 'datetime');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['asset_id'], 'uniq_quarantine_asset');
        $table->addIndex(['quarantined_at'], 'idx_quarantine_at');

        $this->migration()->up($schema);

        $quarantine = $schema->getTable(Installer::TABLE_QUARANTINE);
        $status = $quarantine->getColumn('status');
        self::assertTrue($status->getNotnull());
        self::assertSame(20, $status->getLength());
        self::assertSame(QuarantineStatus::Committed->value, $status->getDefault());
        self::assertSame(
            ['status', 'quarantined_at'],
            $quarantine->getIndex('idx_quarantine_status_at')->getColumns(),
        );
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
    public function downRemovesOnlyTheLifecycleState(): void
    {
        $schema = new Schema();
        $migration = $this->migration();
        $migration->up($schema);

        $migration->down($schema);

        $quarantine = $schema->getTable(Installer::TABLE_QUARANTINE);
        self::assertFalse($quarantine->hasColumn('status'));
        self::assertFalse($quarantine->hasIndex('idx_quarantine_status_at'));
        self::assertTrue($quarantine->hasColumn('original_path'));
        self::assertTrue($quarantine->hasIndex('idx_quarantine_at'));
    }

    #[Test]
    public function isScopedToThisBundle(): void
    {
        $method = new \ReflectionMethod(Version20260715120000::class, 'getBundleName');

        self::assertSame('OrontsAssetPilotBundle', $method->invoke($this->migration()));
    }
}
