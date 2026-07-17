<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

class DependencyUsageVerifier implements DependencyUsageVerifierInterface
{
    public function __construct(
        private readonly DependencyProjectionInterface $projection,
        private readonly DependencyProjectionFreshnessInterface $freshness,
        private readonly LiveDependencyUsageScanner $bootstrapScanner,
        private readonly LoggerInterface $logger,
        private readonly bool $bootstrapFallback = true,
    ) {}

    public function verdict(Asset $asset): DependencyUsageVerdict
    {
        $assetId = (int) $asset->getId();
        if ($assetId <= 0) {
            return DependencyUsageVerdict::Unknown;
        }

        try {
            $snapshot = $this->projection->referenceSnapshot($assetId);
            if ($snapshot->referenced) {
                return DependencyUsageVerdict::Referenced;
            }
            if ($snapshot->dirty) {
                return DependencyUsageVerdict::Unknown;
            }

            $status = $this->freshness->status();
            if ($status->state === DependencyProjectionState::Ready) {
                return DependencyUsageVerdict::Safe;
            }
            if ($this->bootstrapFallback && $status->state === DependencyProjectionState::BootstrapRequired) {
                return $this->bootstrapScanner->verdict($asset);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: dependency usage could not be verified; destructive actions remain blocked.', [
                'asset_id' => $assetId,
                'exception' => $e,
            ]);
        }

        return DependencyUsageVerdict::Unknown;
    }
}
