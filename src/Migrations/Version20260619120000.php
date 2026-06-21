<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Pimcore\Migrations\BundleAwareMigration;

/**
 * Replaces the single-column idx_audit_status with composite audit indexes for installs created before
 * the change. A fresh install already has the target schema (Installer) and marks this version applied
 * via getLastMigrationVersionClassName().
 */
class Version20260619120000 extends BundleAwareMigration
{
    /** @var array<string, list<string>> */
    private const array INDEXES = [
        'idx_audit_status_created' => ['status', 'created_at'],
        'idx_audit_class_created' => ['object_class', 'created_at'],
    ];

    /** Redundant once idx_audit_status_created exists (its left prefix covers status-only lookups). */
    private const string SUPERSEDED_INDEX = 'idx_audit_status';

    public function getDescription(): string
    {
        return 'Add composite audit-log indexes (status, created_at) and (object_class, created_at), drop idx_audit_status.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(Installer::TABLE_AUDIT_LOG)) {
            return;
        }

        $table = $schema->getTable(Installer::TABLE_AUDIT_LOG);
        foreach (self::INDEXES as $name => $columns) {
            if (!$table->hasIndex($name)) {
                $table->addIndex($columns, $name);
            }
        }
        if ($table->hasIndex(self::SUPERSEDED_INDEX)) {
            $table->dropIndex(self::SUPERSEDED_INDEX);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(Installer::TABLE_AUDIT_LOG)) {
            return;
        }

        $table = $schema->getTable(Installer::TABLE_AUDIT_LOG);
        if (!$table->hasIndex(self::SUPERSEDED_INDEX)) {
            $table->addIndex(['status'], self::SUPERSEDED_INDEX);
        }
        foreach (array_keys(self::INDEXES) as $name) {
            if ($table->hasIndex($name)) {
                $table->dropIndex($name);
            }
        }
    }

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
