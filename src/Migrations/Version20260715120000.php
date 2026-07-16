<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

final class Version20260715120000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add pending and committed quarantine lifecycle state for crash-safe recovery and purge.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(Installer::TABLE_QUARANTINE)) {
            return;
        }

        $table = $schema->getTable(Installer::TABLE_QUARANTINE);
        if ($table->hasIndex('idx_quarantine_status_at')) {
            $table->dropIndex('idx_quarantine_status_at');
        }
        if ($table->hasColumn('status')) {
            $table->dropColumn('status');
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
