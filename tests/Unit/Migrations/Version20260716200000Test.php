<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Migrations\Version20260716200000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260716200000::class)]
final class Version20260716200000Test extends TestCase
{
    #[Test]
    public function addsAndRemovesOnlyTheDeliveryAuditReconciliationState(): void
    {
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $delivery = $schema->getTable(Installer::TABLE_OPERATION_DELIVERY);
        $delivery->dropIndex('idx_operation_delivery_audit_reconcile');
        $delivery->dropColumn('audit_reconciled_at');
        $migration = (new \ReflectionClass(Version20260716200000::class))->newInstanceWithoutConstructor();

        $migration->up($schema);

        $delivery = $schema->getTable(Installer::TABLE_OPERATION_DELIVERY);
        self::assertTrue($delivery->hasColumn('audit_reconciled_at'));
        self::assertTrue($delivery->hasIndex('idx_operation_delivery_audit_reconcile'));

        $migration->down($schema);

        self::assertTrue($schema->hasTable(Installer::TABLE_OPERATION_DELIVERY));
        self::assertFalse($delivery->hasColumn('audit_reconciled_at'));
        self::assertFalse($delivery->hasIndex('idx_operation_delivery_audit_reconcile'));
        self::assertTrue($delivery->hasColumn('delivered_at'));
        self::assertTrue($delivery->hasIndex('idx_operation_delivery_due'));
    }
}
