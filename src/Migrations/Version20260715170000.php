<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

class Version20260715170000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Consolidate historical action-failure audit rows into the completed-with-observer-error lifecycle.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(Installer::TABLE_AUDIT_LOG)) {
            return;
        }

        $this->addSql(
            sprintf(
                'UPDATE %s SET status = :replacement WHERE status = :retired',
                Installer::TABLE_AUDIT_LOG,
            ),
            [
                'replacement' => OperationStatus::CompletedWithObserverError->value,
                'retired' => 'action_failed',
            ],
        );
    }

    public function down(Schema $schema): void {}

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
