<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

/**
 * Reclaims stale asset deletion fences on every maintenance run: rows whose lease expired (the owner
 * crashed after claiming but before releasing) or whose asset is already gone (crashed after the asset
 * commit but before the fence release). Each candidate is reaped only while its asset LoopGuard can be
 * taken non-blockingly, so an active deleter mid-delete is never reaped out from under; the fence's own
 * token+expiry compare-and-delete is the authoritative guard. A failure on one row is isolated so the
 * sweep continues, and every taken lock is released. Exceptions are logged, never rethrown.
 */
class DeletionFenceReaperTask implements TaskInterface
{
    public function __construct(
        protected readonly AssetDeletionFenceInterface $fence,
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
        protected readonly int $batchSize = 1000,
    ) {}

    public function execute(): void
    {
        try {
            $candidates = $this->fence->expiredFenceCandidates($this->batchSize);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: deletion-fence reaper could not list candidates: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return;
        }

        $reaped = 0;
        $skipped = 0;
        foreach ($candidates as $assetId) {
            $locked = false;
            try {
                $locked = $this->loopGuard->acquireAsset($assetId);
                if (!$locked) {
                    ++$skipped;

                    continue;
                }
                if ($this->fence->reapAsset($assetId)) {
                    ++$reaped;
                } else {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                ++$skipped;
                $this->logger->error('Asset Pilot: deletion-fence reaper failed for asset {asset}: {error}', [
                    'asset' => $assetId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            } finally {
                if ($locked) {
                    $this->loopGuard->releaseAsset($assetId);
                }
            }
        }

        if ($reaped > 0 || $skipped > 0) {
            $this->logger->info(
                'Asset Pilot: deletion-fence reaper removed {reaped}, skipped {skipped}.',
                ['reaped' => $reaped, 'skipped' => $skipped],
            );
        }
    }
}
