<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

final class Version20260716180000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add the durable dependency projection and its rebuild freshness state.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        foreach ([Installer::TABLE_DEPENDENCY_EDGE, Installer::TABLE_DEPENDENCY_SOURCE, Installer::TABLE_DEPENDENCY_FRESHNESS] as $table) {
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
