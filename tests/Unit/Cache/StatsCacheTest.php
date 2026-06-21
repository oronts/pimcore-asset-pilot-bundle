<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Cache;

use Oronts\AssetPilotBundle\Cache\StatsCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(StatsCache::class)]
class StatsCacheTest extends TestCase
{
    #[Test]
    public function computesOnceThenServesFromCache(): void
    {
        $cache = new StatsCache(new ArrayAdapter());
        $calls = 0;
        $compute = function () use (&$calls): int {
            ++$calls;

            return 42;
        };

        self::assertSame(42, $cache->remember('k', 60, $compute));
        self::assertSame(42, $cache->remember('k', 60, $compute));
        self::assertSame(1, $calls, 'the second call is served from cache');
    }

    #[Test]
    public function aNonPositiveTtlAlwaysComputes(): void
    {
        $cache = new StatsCache(new ArrayAdapter());
        $calls = 0;
        $compute = function () use (&$calls): int {
            ++$calls;

            return 7;
        };

        $cache->remember('k', 0, $compute);
        $cache->remember('k', 0, $compute);
        $cache->remember('k', -5, $compute);

        self::assertSame(3, $calls, 'ttl <= 0 bypasses the cache');
    }

    #[Test]
    public function deleteEvictsSoTheNextCallRecomputes(): void
    {
        $cache = new StatsCache(new ArrayAdapter());
        $calls = 0;
        $compute = function () use (&$calls): int {
            ++$calls;

            return 1;
        };

        $cache->remember('k', 60, $compute);
        $cache->delete('k');
        $cache->remember('k', 60, $compute);

        self::assertSame(2, $calls, 'a deleted key is recomputed');
    }
}
