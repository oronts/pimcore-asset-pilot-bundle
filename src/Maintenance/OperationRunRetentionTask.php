<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Service\OperationRunRetentionInterface;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;

final class OperationRunRetentionTask implements TaskInterface
{
    public function __construct(
        private readonly OperationRunRetentionInterface $retention,
        private readonly LoggerInterface $logger,
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
    }
}
