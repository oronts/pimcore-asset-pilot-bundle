<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Audit\AuditRetentionInterface;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

/**
 * Prunes audit-log entries past the configured retention window on every Pimcore maintenance run,
 * so retention does not depend on a separate cron (`asset-pilot:audit --cleanup`). Exceptions are
 * logged, never rethrown: a failing task must not break the shared maintenance run.
 */
class AuditRetentionTask implements TaskInterface
{
    public function __construct(
        protected readonly AuditRetentionInterface $auditLogger,
        protected readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        try {
            $deleted = $this->auditLogger->cleanup($this->auditLogger->getRetentionDays());
            $this->logger->info('Asset Pilot: audit retention task pruned {count} entries.', ['count' => $deleted]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: audit retention task failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
