<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

class Version20260802000000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add the durable automatic-organize intent table so concurrent saves of the same object coalesce into one pending run instead of racing to create duplicate runs.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT)) {
            $schema->dropTable(Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT);
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
