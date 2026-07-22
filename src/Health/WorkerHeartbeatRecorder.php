<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

class WorkerHeartbeatRecorder
{
    public const array REQUIRED_TRANSPORTS = ['asset_pilot', 'pimcore_maintenance'];

    public function __construct(
        protected readonly CacheItemPoolInterface $cache,
        protected readonly int $heartbeatMaxAge,
        protected readonly LoggerInterface $logger,
    ) {}

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $transportNames = $event->getWorker()->getMetadata()->getTransportNames();

        foreach (array_intersect(self::REQUIRED_TRANSPORTS, $transportNames) as $transportName) {
            try {
                $item = $this->cache->getItem(self::cacheKey($transportName));
                $item->set($this->now());
                $item->expiresAfter($this->heartbeatMaxAge * 3);
                if (!$this->cache->save($item)) {
                    throw new \RuntimeException('The cache rejected the worker heartbeat.');
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Asset Pilot could not record a Messenger worker heartbeat.', [
                    'transport' => $transportName,
                    'exception' => $e,
                ]);
            }
        }
    }

    public static function cacheKey(string $transportName): string
    {
        return 'asset_pilot.worker_heartbeat.' . $transportName;
    }

    protected function now(): int
    {
        return time();
    }
}
