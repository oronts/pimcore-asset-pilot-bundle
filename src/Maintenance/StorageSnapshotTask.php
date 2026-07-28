<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Service\StorageTrendServiceInterface;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

/**
 * Requests a storage snapshot on maintenance runs; the service enforces the configured cadence.
 */
class StorageSnapshotTask implements TaskInterface
{
    public function __construct(
        protected readonly StorageTrendServiceInterface $trends,
        protected readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        try {
            $result = $this->trends->capture();
            if ($result['captured']) {
                $this->logger->info('Asset Pilot: captured storage snapshot run {run} for {types} type(s).', [
                    'run' => $result['runId'],
                    'types' => $result['types'],
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: storage snapshot task failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
