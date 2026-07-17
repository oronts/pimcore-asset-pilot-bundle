<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

final class Version20260717000000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add the asset deletion fence table that blocks concurrent reference commits to an asset during its hard delete.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Installer::TABLE_ASSET_DELETION_FENCE)) {
            $schema->dropTable(Installer::TABLE_ASSET_DELETION_FENCE);
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
