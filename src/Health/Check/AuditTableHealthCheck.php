<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Log\LoggerInterface;

/**
 * The audit logger swallows write errors by design, so a missing table is otherwise silent. When
 * auditing is enabled, verify the table exists; if it cannot be checked, warn rather than claim a
 * failure.
 */
class AuditTableHealthCheck implements HealthCheckInterface
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'audit_table';
    }

    public function run(): HealthCheckResult
    {
        if (!$this->auditLogger->isEnabled()) {
            return new HealthCheckResult($this->name(), HealthStatus::Ok, 'Audit logging is disabled.');
        }

        try {
            $exists = $this->tableExists();
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: could not verify the audit table: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new HealthCheckResult($this->name(), HealthStatus::Warning, 'Could not verify the audit table.');
        }

        if (!$exists) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                sprintf('Audit table "%s" is missing; run the bundle installer. Audit logging silently no-ops without it.', AuditLogger::TABLE_NAME),
            );
        }

        return new HealthCheckResult($this->name(), HealthStatus::Ok, 'Audit table is present.');
    }

    protected function tableExists(): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([AuditLogger::TABLE_NAME]);
    }
}
