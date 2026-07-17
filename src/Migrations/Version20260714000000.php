<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy;
use Pimcore\Migrations\BundleAwareMigration;

class Version20260714000000 extends BundleAwareMigration
{
    public function getDescription(): string
    {
        return 'Repair every Asset Pilot table, column, primary key, and index for upgrades from v1.0 and partial v1.1 installations.';
    }

    public function up(Schema $schema): void
    {
        Installer::ensureCurrentSchema($schema);

        $this->addSql(
            <<<'SQL'
                INSERT INTO properties (cid, ctype, cpath, name, type, data, inheritable)
                SELECT DISTINCT a.id, 'asset', SUBSTRING(CONCAT(a.path, a.filename), 1, 765), :propertyName, 'bool', '1', 0
                FROM assets a
                INNER JOIN asset_pilot_audit_log audit ON audit.asset_id = a.id
                    AND audit.status IN (:completedStatus, :completedWithObserverErrorStatus, :actionFailedStatus)
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM properties existing_property
                    WHERE existing_property.cid = a.id
                      AND existing_property.ctype = 'asset'
                      AND existing_property.name = :propertyName
                )
                SQL,
            [
                'propertyName' => FirstAssignmentStrategy::ASSIGNMENT_PROPERTY,
                'completedStatus' => OperationStatus::Completed->value,
                'completedWithObserverErrorStatus' => OperationStatus::CompletedWithObserverError->value,
                'actionFailedStatus' => 'action_failed',
            ],
        );
    }

    public function postUp(Schema $schema): void
    {
        parent::postUp($schema);

        $capturedAtValues = $this->connection->createQueryBuilder()
            ->select('DISTINCT captured_at')
            ->from(Installer::TABLE_STORAGE_SNAPSHOT)
            ->where('run_id IS NULL')
            ->orderBy('captured_at', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($capturedAtValues as $capturedAt) {
            $this->collapseDuplicateSnapshots((string) $capturedAt);

            $totals = $this->connection->createQueryBuilder()
                ->select(
                    'COALESCE(SUM(unused_count), 0) AS total_count',
                    'COALESCE(SUM(unused_size), 0) AS total_size',
                    'COALESCE(SUM(unknown_size_count), 0) AS unknown_size_count',
                )
                ->from(Installer::TABLE_STORAGE_SNAPSHOT)
                ->where('run_id IS NULL')
                ->andWhere('captured_at = :capturedAt')
                ->setParameter('capturedAt', $capturedAt)
                ->executeQuery()
                ->fetchAssociative();
            if ($totals === false) {
                continue;
            }

            $this->connection->insert(Installer::TABLE_STORAGE_RUN, [
                'started_at' => $capturedAt,
                'completed_at' => $capturedAt,
                'status' => OperationStatus::Completed->value,
                'total_count' => (int) $totals['total_count'],
                'total_size' => (int) $totals['total_size'],
                'unknown_size_count' => (int) $totals['unknown_size_count'],
                'error_message' => null,
            ]);
            $runId = (int) $this->connection->lastInsertId();
            $this->connection->createQueryBuilder()
                ->update(Installer::TABLE_STORAGE_SNAPSHOT)
                ->set('run_id', ':runId')
                ->where('run_id IS NULL')
                ->andWhere('captured_at = :capturedAt')
                ->setParameter('runId', $runId)
                ->setParameter('capturedAt', $capturedAt)
                ->executeStatement();
        }
    }

    private function collapseDuplicateSnapshots(string $capturedAt): void
    {
        $snapshots = $this->connection->createQueryBuilder()
            ->select(
                'MIN(id) AS canonical_id',
                'type',
                'COALESCE(SUM(unused_count), 0) AS unused_count',
                'COALESCE(SUM(unused_size), 0) AS unused_size',
                'COALESCE(SUM(unknown_size_count), 0) AS unknown_size_count',
            )
            ->from(Installer::TABLE_STORAGE_SNAPSHOT)
            ->where('run_id IS NULL')
            ->andWhere('captured_at = :capturedAt')
            ->groupBy('type')
            ->setParameter('capturedAt', $capturedAt)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($snapshots as $snapshot) {
            $canonicalId = (int) $snapshot['canonical_id'];
            $this->connection->createQueryBuilder()
                ->update(Installer::TABLE_STORAGE_SNAPSHOT)
                ->set('unused_count', ':unusedCount')
                ->set('unused_size', ':unusedSize')
                ->set('unknown_size_count', ':unknownSizeCount')
                ->where('id = :canonicalId')
                ->andWhere('run_id IS NULL')
                ->setParameter('unusedCount', (int) $snapshot['unused_count'])
                ->setParameter('unusedSize', (int) $snapshot['unused_size'])
                ->setParameter('unknownSizeCount', (int) $snapshot['unknown_size_count'])
                ->setParameter('canonicalId', $canonicalId)
                ->executeStatement();

            $this->connection->createQueryBuilder()
                ->delete(Installer::TABLE_STORAGE_SNAPSHOT)
                ->where('run_id IS NULL')
                ->andWhere('captured_at = :capturedAt')
                ->andWhere('type = :type')
                ->andWhere('id <> :canonicalId')
                ->setParameter('capturedAt', $capturedAt)
                ->setParameter('type', (string) $snapshot['type'])
                ->setParameter('canonicalId', $canonicalId)
                ->executeStatement();
        }
    }

    public function down(Schema $schema): void {}

    protected function getBundleName(): string
    {
        return 'OrontsAssetPilotBundle';
    }
}
