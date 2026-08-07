<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class DependencyProjectionReconcileTask implements TaskInterface
{
    public function __construct(
        protected readonly DependencyProjectionInterface $projection,
        protected readonly MessageBusInterface $bus,
        protected readonly LoggerInterface $logger,
        protected readonly int $staleSeconds = 300,
        protected readonly int $batchSize = 100,
    ) {}

    public function execute(): void
    {
        $before = $this->cutoff();

        try {
            $redispatched = 0;
            foreach ($this->projection->staleDirtySources($before, $this->batchSize) as $source) {
                $this->bus->dispatch(new DependencyProjectionRefreshMessage($source['sourceType'], $source['sourceId']));
                $redispatched++;
            }
            if ($redispatched > 0) {
                $this->logger->warning('Asset Pilot: re-dispatched {count} stale dirty dependency sources for reconciliation.', ['count' => $redispatched]);
            }

            $orphans = $this->projection->pruneStaleOrphanPendingSources($before);
            if ($orphans > 0) {
                $this->logger->warning('Asset Pilot: cleared {count} stale orphan pending dependency sources.', ['count' => $orphans]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: dependency projection reconciliation failed.', ['exception' => $e]);
        }
    }

    protected function cutoff(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('PT' . max(0, $this->staleSeconds) . 'S'))
            ->format('Y-m-d H:i:s');
    }
}
