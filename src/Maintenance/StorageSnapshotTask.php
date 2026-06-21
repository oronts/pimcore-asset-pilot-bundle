<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Service\StorageTrendService;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

/**
 * Captures an unused-storage snapshot on every Pimcore maintenance run so the storage trend builds
 * up without a separate cron. Exceptions are logged, never rethrown: a failing task must not break
 * the shared maintenance run.
 */
class StorageSnapshotTask implements TaskInterface
{
    public function __construct(
        protected readonly StorageTrendService $trends,
        protected readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        try {
            $result = $this->trends->capture();
            $this->logger->info('Asset Pilot: captured storage snapshot for {types} type(s).', ['types' => $result['types']]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: storage snapshot task failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
