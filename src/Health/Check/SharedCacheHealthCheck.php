<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Health\WorkerHeartbeatRecorder;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Cache\CacheItemPoolInterface;

class SharedCacheHealthCheck implements HealthCheckInterface
{
    /** Substrings of Symfony cache adapters that are not guaranteed shared across workers/pods. */
    private const array NON_SHARED_MARKERS = ['Array', 'Filesystem', 'PhpFiles', 'PhpArray', 'Apcu', 'Null'];

    public function __construct(
        protected readonly CacheItemPoolInterface $cache,
        protected readonly bool $asyncEnabled = true,
        protected readonly int $heartbeatMaxAge = 120,
    ) {}

    public function name(): string
    {
        return 'shared_cache';
    }

    public function run(): HealthCheckResult
    {
        $class = $this->cache::class;
        $shortName = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;

        foreach (self::NON_SHARED_MARKERS as $marker) {
            if (str_contains($shortName, $marker)) {
                return new HealthCheckResult(
                    $this->name(),
                    HealthStatus::Warning,
                    sprintf('cache.app (%s) is not guaranteed shared across workers/pods, so LoopGuard idempotency may not hold there. Use a shared store (Redis) in production.', $shortName),
                    ['adapter' => $class],
                );
            }
        }

        $verifiedBy = $this->freshWorkerHeartbeats();
        if ($verifiedBy !== []) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Ok,
                sprintf('Cross-process cache visibility is proven by fresh worker heartbeat(s): %s.', implode(', ', $verifiedBy)),
                ['adapter' => $class, 'verified_by' => $verifiedBy],
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Warning,
            sprintf('cache.app (%s) may be shared, but cross-worker visibility was not proven by this process.', $shortName),
            ['adapter' => $class],
        );
    }

    /** @return list<string> */
    private function freshWorkerHeartbeats(): array
    {
        $required = $this->asyncEnabled ? WorkerHeartbeatRecorder::REQUIRED_TRANSPORTS : ['pimcore_maintenance'];
        $now = $this->now();
        $fresh = [];
        foreach ($required as $transportName) {
            try {
                $item = $this->cache->getItem(WorkerHeartbeatRecorder::cacheKey($transportName));
                $timestamp = $item->isHit() ? $item->get() : null;
                if (is_int($timestamp) && max(0, $now - $timestamp) <= $this->heartbeatMaxAge) {
                    $fresh[] = $transportName;
                }
            } catch (\Throwable) {
                return [];
            }
        }

        return count($fresh) === count($required) ? $fresh : [];
    }

    protected function now(): int
    {
        return time();
    }
}
