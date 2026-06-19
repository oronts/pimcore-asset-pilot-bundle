<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache-aside helper for the read-only stats endpoints: serve a value from the shared pool within a
 * short TTL, otherwise compute and store it. A non-positive TTL bypasses the cache, which keeps the
 * schedule/maintenance callers (and tests) on live data.
 */
class StatsCache
{
    public function __construct(private readonly CacheItemPoolInterface $pool) {}

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

        $item = $this->pool->getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }

        $value = $compute();
        $item->set($value)->expiresAfter($ttl);
        $this->pool->save($item);

        return $value;
    }

    public function delete(string $key): void
    {
        $this->pool->deleteItem($key);
    }
}
