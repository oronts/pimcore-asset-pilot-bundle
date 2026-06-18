<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Service\QuarantineService;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

/**
 * Hard-deletes quarantined assets past the grace period on every maintenance run (only those still
 * unused; QuarantineService re-verifies). This is the safe, scheduled cleanup the audit-retention
 * task could not be for assets: nothing is deleted that was not first quarantined and left untouched
 * for the grace period. Exceptions are logged, never rethrown.
 */
class QuarantinePurgeTask implements TaskInterface
{
    public function __construct(
        protected readonly QuarantineService $quarantineService,
        protected readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        try {
            $result = $this->quarantineService->purgeExpired();
            $this->logger->info('Asset Pilot: quarantine purge removed {purged}, skipped {skipped}, failed {failed}.', $result);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: quarantine purge task failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
