<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\SharedCacheHealthCheck;
use Oronts\AssetPilotBundle\Health\WorkerHeartbeatRecorder;
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
    public function warnsWhenTheAdapterCannotProveCrossWorkerVisibility(): void
    {
        // An unrecognized pool (e.g. a Redis-backed one) cannot be proven non-shared, so it passes
        // with the introspection caveat rather than a false warning.
        $pool = $this->createMock(CacheItemPoolInterface::class);

        self::assertSame(HealthStatus::Warning, (new SharedCacheHealthCheck($pool))->run()->status);
    }

    #[Test]
    public function isOkWhenFreshHeartbeatsProveCrossProcessVisibility(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $values = ['asset_pilot' => 990, 'pimcore_maintenance' => 995];
        $pool->method('getItem')->willReturnCallback(function (string $key) use ($values) {
            $transport = str_replace('asset_pilot.worker_heartbeat.', '', $key);
            $item = $this->createStub(\Psr\Cache\CacheItemInterface::class);
            $item->method('isHit')->willReturn(isset($values[$transport]));
            $item->method('get')->willReturn($values[$transport] ?? null);

            return $item;
        });
        $check = new class ($pool, true, 120) extends SharedCacheHealthCheck {
            protected function now(): int
            {
                return 1000;
            }
        };

        $result = $check->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame(WorkerHeartbeatRecorder::requiredTransports('asset_pilot'), $result->details['verified_by']);
    }

    #[Test]
    public function requiresTheConfiguredTransportHeartbeatNotTheDefault(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $values = ['custom_queue' => 990, 'pimcore_maintenance' => 995];
        $pool->method('getItem')->willReturnCallback(function (string $key) use ($values) {
            $transport = str_replace('asset_pilot.worker_heartbeat.', '', $key);
            $item = $this->createStub(\Psr\Cache\CacheItemInterface::class);
            $item->method('isHit')->willReturn(isset($values[$transport]));
            $item->method('get')->willReturn($values[$transport] ?? null);

            return $item;
        });
        $check = new class ($pool, true, 120, 'custom_queue') extends SharedCacheHealthCheck {
            protected function now(): int
            {
                return 1000;
            }
        };

        $result = $check->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame(['custom_queue', 'pimcore_maintenance'], $result->details['verified_by']);
    }
}
