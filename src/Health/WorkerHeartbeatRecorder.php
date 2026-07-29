<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

class WorkerHeartbeatRecorder
{
    /** @var list<string> */
    private readonly array $requiredTransports;

    public function __construct(
        protected readonly CacheItemPoolInterface $cache,
        protected readonly int $heartbeatMaxAge,
        protected readonly LoggerInterface $logger,
        string $transportName = 'asset_pilot',
    ) {
        $this->requiredTransports = self::requiredTransports($transportName);
    }

    /**
     * The single source of truth for the receivers whose liveness the health checks require: the
     * configured Asset Pilot transport plus the Pimcore maintenance receiver.
     *
     * @return list<string>
     */
    public static function requiredTransports(string $transportName): array
    {
        return array_values(array_unique([$transportName, 'pimcore_maintenance']));
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $transportNames = $event->getWorker()->getMetadata()->getTransportNames();

        foreach (array_intersect($this->requiredTransports, $transportNames) as $transportName) {
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
