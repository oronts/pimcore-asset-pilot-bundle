<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Log\LoggerInterface;

class AuditTableHealthCheck implements HealthCheckInterface
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'database_schema';
    }

    public function run(): HealthCheckResult
    {
        try {
            $status = $this->schemaStatus();
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: could not verify the database schema: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new HealthCheckResult($this->name(), HealthStatus::Warning, 'Could not verify the database schema.');
        }

        if ($status['missing'] !== []) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                'Asset Pilot database tables are missing. Run the bundle migrations.',
                ['missing_tables' => $status['missing']],
            );
        }

        if (!$status['current']) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                'Asset Pilot database columns or indexes do not match the current schema. Run the bundle migrations.',
            );
        }

        return new HealthCheckResult($this->name(), HealthStatus::Ok, 'All Asset Pilot database tables match the current schema.');
    }

    /** @return array{current: bool, missing: list<string>} */
    protected function schemaStatus(): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        $current = $schemaManager->introspectSchema();
        $target = clone $current;
        Installer::ensureCurrentSchema($target);
        $missing = array_values(array_filter(
            [
                Installer::TABLE_APPLY_PLAN_CLAIM,
                Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT,
                Installer::TABLE_AUDIT_LOG,
                Installer::TABLE_QUARANTINE,
                Installer::TABLE_INTEGRITY_LOG,
                Installer::TABLE_CHECKSUM,
                Installer::TABLE_STORAGE_SNAPSHOT,
                Installer::TABLE_STORAGE_RUN,
                Installer::TABLE_OPERATION_RUN,
                Installer::TABLE_OPERATION_RUN_ITEM,
                Installer::TABLE_OPERATION_DELIVERY,
                Installer::TABLE_DEPENDENCY_SOURCE,
                Installer::TABLE_DEPENDENCY_EDGE,
                Installer::TABLE_DEPENDENCY_FRESHNESS,
            ],
            static fn (string $table): bool => !$current->hasTable($table),
        ));

        return [
            'current' => $schemaManager->createComparator()->compareSchemas($current, $target)->isEmpty(),
            'missing' => $missing,
        ];
    }
}
