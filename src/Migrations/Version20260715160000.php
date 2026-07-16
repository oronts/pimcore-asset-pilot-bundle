<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

final class Version20260715160000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add the durable mutation journal metadata and observer delivery outbox.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Installer::TABLE_OPERATION_DELIVERY)) {
            $schema->dropTable(Installer::TABLE_OPERATION_DELIVERY);
        }
        if (!$schema->hasTable(Installer::TABLE_AUDIT_LOG)) {
            return;
        }

        $audit = $schema->getTable(Installer::TABLE_AUDIT_LOG);
        foreach (['operation_kind', 'actor_type', 'parent_audit_id', 'intent_payload', 'schema_version', 'updated_at', 'committed_at'] as $column) {
            if ($audit->hasColumn($column)) {
                $audit->dropColumn($column);
            }
        }
        foreach (['idx_audit_recovery', 'idx_audit_parent'] as $index) {
            if ($audit->hasIndex($index)) {
                $audit->dropIndex($index);
            }
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
