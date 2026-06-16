<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(LoopGuard::class)]
class LoopGuardTest extends TestCase
{
    private CacheItemPoolInterface $cache;
    private LoopGuard $guard;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->guard = new LoopGuard($this->cache, new LockFactory(new InMemoryStore()));
    }

    #[Test]
    public function acquireObjectIsExclusiveAcrossJobsAndReleasableByTheOwner(): void
    {
        $store = new InMemoryStore();
        $jobA = new LoopGuard($this->cache, new LockFactory($store));
        $jobB = new LoopGuard($this->cache, new LockFactory($store));

        self::assertTrue($jobA->acquireObject(42));
        self::assertFalse($jobB->acquireObject(42), 'a second job must skip while the first holds the lock');

        $jobA->releaseObject(42);
        self::assertTrue($jobB->acquireObject(42), 'the lock is free once the owner releases it');
    }

    #[Test]
    public function releasingALockYouDoNotOwnDoesNotFreeTheCurrentHolder(): void
    {
        $store = new InMemoryStore();
        $jobA = new LoopGuard($this->cache, new LockFactory($store));
        $jobB = new LoopGuard($this->cache, new LockFactory($store));

        self::assertTrue($jobA->acquireAsset(7));
        $jobB->releaseAsset(7); // jobB never acquired — must be a no-op, not free jobA's lock

        self::assertFalse($jobB->acquireAsset(7), 'jobA still holds the lock');
    }

    #[Test]
    public function objectAndAssetLocksUseSeparateResources(): void
    {
        self::assertTrue($this->guard->acquireObject(1));
        self::assertTrue($this->guard->acquireAsset(1));
    }

    #[Test]
    public function refreshObjectIsANoOpWhenNoLockIsHeld(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard->refreshObject(99); // must not throw when nothing is held
    }

    #[Test]
    public function refreshObjectExtendsAHeldLock(): void
    {
        self::assertTrue($this->guard->acquireObject(5));
        $this->guard->refreshObject(5); // still ours -> no exception

        self::assertTrue(true);
    }

    #[Test]
    public function isProcessingAssetReturnsFalseWhenNotCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.asset.42')
            ->willReturn(false);

        self::assertFalse($this->guard->isProcessingAsset(42));
    }

    #[Test]
    public function isProcessingAssetReturnsTrueWhenCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.asset.42')
            ->willReturn(true);

        self::assertTrue($this->guard->isProcessingAsset(42));
    }

    #[Test]
    public function markAssetProcessingSavesItem(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with(true)->willReturn($item);
        $item->expects(self::once())->method('expiresAfter')->with(60)->willReturn($item);

        $this->cache->method('getItem')
            ->with('asset_pilot.loop_guard.asset.42')
            ->willReturn($item);

        $this->cache->expects(self::once())->method('save')->with($item);

        $this->guard->markAssetProcessing(42);
    }

    #[Test]
    public function unmarkAssetProcessingDeletesItem(): void
    {
        $this->cache->expects(self::once())
            ->method('deleteItem')
            ->with('asset_pilot.loop_guard.asset.42');

        $this->guard->unmarkAssetProcessing(42);
    }

    #[Test]
    public function isProcessingObjectReturnsFalseWhenNotCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.object.99')
            ->willReturn(false);

        self::assertFalse($this->guard->isProcessingObject(99));
    }

    #[Test]
    public function isProcessingObjectReturnsTrueWhenCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.object.99')
            ->willReturn(true);

        self::assertTrue($this->guard->isProcessingObject(99));
    }

    #[Test]
    public function markObjectProcessingSavesItem(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with(true)->willReturn($item);
        $item->expects(self::once())->method('expiresAfter')->with(60)->willReturn($item);

        $this->cache->method('getItem')
            ->with('asset_pilot.loop_guard.object.99')
            ->willReturn($item);

        $this->cache->expects(self::once())->method('save')->with($item);

        $this->guard->markObjectProcessing(99);
    }

    #[Test]
    public function unmarkObjectProcessingDeletesItem(): void
    {
        $this->cache->expects(self::once())
            ->method('deleteItem')
            ->with('asset_pilot.loop_guard.object.99');

        $this->guard->unmarkObjectProcessing(99);
    }

    #[Test]
    public function markAssetRecentlyMovedUses300SecondTtl(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with(true)->willReturn($item);
        $item->expects(self::once())->method('expiresAfter')->with(300)->willReturn($item);

        $this->cache->method('getItem')
            ->with('asset_pilot.recently_moved.42')
            ->willReturn($item);

        $this->cache->expects(self::once())->method('save')->with($item);

        $this->guard->markAssetRecentlyMoved(42);
    }

    #[Test]
    public function wasAssetRecentlyMovedChecksCache(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.recently_moved.42')
            ->willReturn(true);

        self::assertTrue($this->guard->wasAssetRecentlyMoved(42));
    }

    #[Test]
    public function wasAssetRecentlyMovedReturnsFalseWhenNotCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.recently_moved.42')
            ->willReturn(false);

        self::assertFalse($this->guard->wasAssetRecentlyMoved(42));
    }
}
