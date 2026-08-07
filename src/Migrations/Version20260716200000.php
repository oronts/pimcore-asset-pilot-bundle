<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

class Version20260716200000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Persist durable-delivery audit reconciliation state.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(Installer::TABLE_OPERATION_DELIVERY)) {
            return;
        }

        $table = $schema->getTable(Installer::TABLE_OPERATION_DELIVERY);
        if ($table->hasIndex('idx_operation_delivery_audit_reconcile')) {
            $table->dropIndex('idx_operation_delivery_audit_reconcile');
        }
        if ($table->hasColumn('audit_reconciled_at')) {
            $table->dropColumn('audit_reconciled_at');
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
