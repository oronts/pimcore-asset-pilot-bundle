<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Cache;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Cache-aside helper for the read-only stats endpoints. Uses the Symfony Contracts cache get(), which
 * provides built-in stampede protection (one worker recomputes a concurrent miss while the others wait
 * or serve the stale value) across PHP-FPM, Messenger workers and multiple pods. A non-positive TTL
 * bypasses the cache, which keeps the schedule/maintenance callers (and tests) on live data.
 */
class StatsCache
{
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * @template T
     *
     * @param callable():T $compute
     *
     * @return T
     */
    public function remember(string $key, int $ttl, callable $compute): mixed
    {
        if ($ttl <= 0) {
            return $compute();
        }

        return $this->cache->get($key, static function (ItemInterface $item) use ($ttl, $compute) {
            $item->expiresAfter($ttl);

            return $compute();
        });
    }

    public function delete(string $key): void
    {
        $this->cache->delete($key);
    }
}
