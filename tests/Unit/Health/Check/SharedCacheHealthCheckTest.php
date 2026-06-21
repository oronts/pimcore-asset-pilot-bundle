<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\SharedCacheHealthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(SharedCacheHealthCheck::class)]
class SharedCacheHealthCheckTest extends TestCase
{
    #[Test]
    public function warnsWhenTheCacheIsAKnownNonSharedAdapter(): void
    {
        $result = (new SharedCacheHealthCheck(new ArrayAdapter()))->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertSame(ArrayAdapter::class, $result->details['adapter']);
    }

    #[Test]
    public function okWhenTheAdapterIsNotAKnownNonSharedStore(): void
    {
        // An unrecognized pool (e.g. a Redis-backed one) cannot be proven non-shared, so it passes
        // with the introspection caveat rather than a false warning.
        $pool = $this->createMock(CacheItemPoolInterface::class);

        self::assertSame(HealthStatus::Ok, (new SharedCacheHealthCheck($pool))->run()->status);
    }
}
