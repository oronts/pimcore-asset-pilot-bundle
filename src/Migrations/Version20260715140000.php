<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

final class Version20260715140000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Add resumable item state and blocked outcomes to durable operation runs.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Installer::TABLE_OPERATION_RUN)) {
            $run = $schema->getTable(Installer::TABLE_OPERATION_RUN);
            if ($run->hasColumn('blocked_count')) {
                $run->dropColumn('blocked_count');
            }
        }
        if ($schema->hasTable(Installer::TABLE_OPERATION_RUN_ITEM)) {
            $item = $schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM);
            if ($item->hasColumn('state_payload')) {
                $item->dropColumn('state_payload');
            }
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
