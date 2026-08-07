<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

class Version20260721000000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add a claim-token-fenced liveness lease (claim_token, lease_expires_at) to operation run items so stale-run reconciliation fails only items whose durable lease expired, never a legitimately long-running or broker-queued run.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(Installer::TABLE_OPERATION_RUN_ITEM)) {
            return;
        }

        $table = $schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM);
        if ($table->hasIndex('idx_operation_item_lease')) {
            $table->dropIndex('idx_operation_item_lease');
        }
        foreach (['lease_expires_at', 'claim_token'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
