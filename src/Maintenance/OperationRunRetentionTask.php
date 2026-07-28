<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Service\OperationRunRetentionInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

final class OperationRunRetentionTask implements TaskInterface
{
    private const int RECONCILE_BATCH = 100;

    public function __construct(
        private readonly OperationRunRetentionInterface $retention,
        private readonly OperationRunStoreInterface $runs,
        private readonly LoggerInterface $logger,
        private readonly int $staleRunSeconds = 86400,
    ) {}

    public function execute(): void
    {
        try {
            $deleted = $this->retention->prune();
            if ($deleted > 0) {
                $this->logger->info('Asset Pilot: pruned {count} expired operation runs.', ['count' => $deleted]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: operation run retention failed.', ['exception' => $e]);
        }

        try {
            $reconciled = $this->runs->reconcileExpiredItemLeases(self::RECONCILE_BATCH);
            if ($reconciled > 0) {
                $this->logger->warning('Asset Pilot: reconciled {count} operation run items whose lease expired as failed.', ['count' => $reconciled]);
            }

            $finalized = $this->runs->reconcileUnfinalizedRuns(self::RECONCILE_BATCH);
            if ($finalized > 0) {
                $this->logger->warning('Asset Pilot: finalized {count} operation runs whose finalization was interrupted.', ['count' => $finalized]);
            }

            $abandoned = $this->runs->reconcileAbandonedRunningRuns(self::RECONCILE_BATCH, $this->staleRunSeconds);
            if ($abandoned > 0) {
                $this->logger->warning('Asset Pilot: failed {count} abandoned operation runs that stalled with no live worker.', ['count' => $abandoned]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: operation run item reconciliation failed.', ['exception' => $e]);
        }
    }
}
