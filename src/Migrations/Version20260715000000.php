<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

final class Version20260715000000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add durable operation runs and per-item progress for mutation tracking, cancellation, and retry.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        foreach ([Installer::TABLE_OPERATION_RUN_ITEM, Installer::TABLE_OPERATION_RUN] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
