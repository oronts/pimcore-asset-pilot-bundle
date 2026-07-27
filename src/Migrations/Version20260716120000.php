<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

class Version20260716120000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Persist reviewed apply plan claims and structured durable observer failures.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Installer::TABLE_APPLY_PLAN_CLAIM)) {
            $schema->dropTable(Installer::TABLE_APPLY_PLAN_CLAIM);
        }
        if ($schema->hasTable(Installer::TABLE_AUDIT_LOG)) {
            $audit = $schema->getTable(Installer::TABLE_AUDIT_LOG);
            if ($audit->hasColumn('durable_observer_failures')) {
                $audit->dropColumn('durable_observer_failures');
            }
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
